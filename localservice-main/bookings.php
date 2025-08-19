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

// Handle filters
$status_filter = $_GET['status'] ?? 'all';
$date_filter = $_GET['date'] ?? '';
$payment_filter = $_GET['payment_status'] ?? 'all';

// Build query with prepared statements
$query = "
    SELECT 
        b.id, b.booking_date, b.status, b.amount, 
        b.cancellation_reason, b.payment_type, b.payment_status, b.address,
        u.name as customer_name, u.email as customer_email, u.phone as customer_phone,
        p.name as provider_name, p.phone as provider_phone,
        s.name as service_name, 
        sc.name as category_name,
        (SELECT image_url FROM service_images WHERE service_id = s.id LIMIT 1) as service_image,
        r.rating, r.comment as review_comment
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    JOIN users p ON b.provider_id = p.id
    JOIN services s ON b.service_id = s.id
    JOIN service_categories sc ON s.category_id = sc.id
    LEFT JOIN reviews r ON b.id = r.booking_id
";

$where = [];
$params = [];
$types = "";

if ($status_filter !== 'all') {
    $where[] = "b.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($date_filter)) {
    $where[] = "DATE(b.booking_date) = ?";
    $params[] = $date_filter;
    $types .= "s";
}

if ($payment_filter !== 'all') {
    $where[] = "b.payment_status = ?";
    $params[] = $payment_filter;
    $types .= "s";
}

if (!empty($where)) {
    $query .= " WHERE " . implode(" AND ", $where);
}

$query .= " ORDER BY b.booking_date DESC";

// Execute query with prepared statement
$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) {
    die("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
}

$bookings = $stmt->get_result();

// Get counts for dashboard
$counts = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(status = 'pending') as pending,
        SUM(status = 'confirmed') as confirmed,
        SUM(status = 'completed') as completed,
        SUM(status = 'cancelled') as cancelled,
        SUM(status = 'rejected') as rejected,
        SUM(payment_status = 'paid') as paid,
        SUM(payment_status = 'pending') as payment_pending,
        SUM(payment_status = 'unpaid') as unpaid
    FROM bookings
")->fetch_assoc();

// Handle booking status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Invalid request";
        header("Location: bookings.php");
        exit;
    }

    if (isset($_POST['update_status'])) {
        $booking_id = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
        $new_status = $_POST['new_status'];
        $notes = isset($_POST['notes']) ? $conn->real_escape_string($_POST['notes']) : '';
        $payment_status = $_POST['payment_status'] ?? null;
        
        if ($booking_id) {
            // Update booking status
            if (($new_status === 'cancelled' || $new_status === 'rejected') && empty($notes)) {
                $_SESSION['error'] = "Please provide a reason for cancellation/rejection";
                header("Location: bookings.php");
                exit;
            }
            
            // Build update query
            $update_fields = ["status = ?", "admin_notes = ?"];
            $update_params = [$new_status, $notes];
            $update_types = "ss";
            
            if ($payment_status) {
                $update_fields[] = "payment_status = ?";
                $update_params[] = $payment_status;
                $update_types .= "s";
            }
            
            if ($new_status === 'cancelled' || $new_status === 'rejected') {
                $update_fields[] = "cancellation_reason = ?";
                $update_params[] = $notes;
                $update_types .= "s";
            }
            
            $update_query = "UPDATE bookings SET " . implode(", ", $update_fields) . " WHERE id = ?";
            $update_params[] = $booking_id;
            $update_types .= "i";
            
            $stmt = $conn->prepare($update_query);
            if (!$stmt) {
                $_SESSION['error'] = "Error preparing update query";
                header("Location: bookings.php");
                exit;
            }
            
            $stmt->bind_param($update_types, ...$update_params);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = "Booking updated successfully";
                
                // Create review opportunity if completed
                if ($new_status === 'completed') {
                    $insert_query = "INSERT INTO review_opportunities (booking_id, user_id, provider_id, service_id) 
                                    SELECT ?, user_id, provider_id, service_id FROM bookings WHERE id = ?";
                    $stmt2 = $conn->prepare($insert_query);
                    $stmt2->bind_param("ii", $booking_id, $booking_id);
                    $stmt2->execute();
                    $stmt2->close();
                }
            } else {
                $_SESSION['error'] = "Error updating booking status: " . $stmt->error;
            }
            $stmt->close();
        }
        
        header("Location: bookings.php");
        exit;
    }
    
    // Handle booking deletion
    if (isset($_POST['delete_booking'])) {
        $booking_id = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
        
        if ($booking_id) {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Delete from dependent tables first
                $conn->query("DELETE FROM reviews WHERE booking_id = $booking_id");
                $conn->query("DELETE FROM review_opportunities WHERE booking_id = $booking_id");
                
                // Then delete the booking
                $stmt = $conn->prepare("DELETE FROM bookings WHERE id = ?");
                $stmt->bind_param("i", $booking_id);
                $stmt->execute();
                
                $conn->commit();
                $_SESSION['message'] = "Booking deleted successfully";
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error'] = "Error deleting booking";
                error_log("Booking deletion error: " . $e->getMessage());
            }
            
            header("Location: bookings.php");
            exit;
        }
    }
}

// Display any messages or errors
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message']);
unset($_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Bookings | UrbanServe</title>
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
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Main Content Styles */
        .main-content {
            flex: 1;
            padding: 30px;
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

        .user-actions {
            display: flex;
            align-items: center;
            gap: 15px;
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

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        .back-link i {
            margin-right: 5px;
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

        /* Booking Specific Styles */
        .booking-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-pending { background-color: rgba(237, 137, 54, 0.1); color: var(--warning); }
        .status-confirmed { background-color: rgba(56, 161, 105, 0.1); color: var(--success); }
        .status-completed { background-color: rgba(66, 153, 225, 0.1); color: #4299e1; }
        .status-cancelled { background-color: rgba(229, 62, 62, 0.1); color: var(--error); }
        .status-rejected { background-color: rgba(229, 62, 62, 0.1); color: var(--error); }
        
        .payment-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .payment-paid { background-color: rgba(56, 161, 105, 0.1); color: var(--success); }
        .payment-pending { background-color: rgba(237, 137, 54, 0.1); color: var(--warning); }
        .payment-unpaid { background-color: rgba(229, 62, 62, 0.1); color: var(--error); }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
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

        
        .booking-details {
            display: none;
            padding: 10px;
            background-color: var(--accent);
            border-radius: 5px;
            margin-top: 10px;
        }

        .booking-details p strong {
            display: inline-block;
            min-width: 120px;
        }

        .cancellation-reason {
            color: var(--error);
            font-weight: 500;
            margin-top: 8px;
            padding: 8px;
            background-color: rgba(229, 62, 62, 0.1);
            border-radius: 4px;
        }

        .service-image {
            width: 80px;
            height: 60px;
            object-fit: cover;
            border-radius: 4px;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: var(--white);
            margin: 10% auto;
            padding: 20px;
            border-radius: 8px;
            width: 50%;
            max-width: 500px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }
        
        .modal-header h3 {
            margin: 0;
            color: var(--secondary);
        }
        
        .close-modal {
            font-size: 24px;
            cursor: pointer;
            color: var(--light-text);
        }
        
        .modal-body {
            margin-bottom: 20px;
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .modal-textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 4px;
            margin-top: 10px;
            resize: vertical;
            min-height: 100px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-container {
                flex-direction: column;
            }
            
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
            
            .modal-content {
                width: 90%;
            }
        }

    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="main-content">
            <div class="header">
                <h1>Manage Bookings</h1>
               
                     <div>
            <a href="admin_dashboard.php" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
          
                </div>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Filter Controls -->
            <form method="GET" class="filter-controls">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="filter-group">
                    <label for="status">Status:</label>
                    <select name="status" id="status">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="confirmed" <?= $status_filter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                        <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="payment_status">Payment:</label>
                    <select name="payment_status" id="payment_status">
                        <option value="all" <?= $payment_filter === 'all' ? 'selected' : '' ?>>All Payments</option>
                        <option value="paid" <?= $payment_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="pending" <?= $payment_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="unpaid" <?= $payment_filter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="date">Date:</label>
                    <input type="date" name="date" id="date" value="<?= htmlspecialchars($date_filter) ?>">
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
                <a href="bookings.php" class="btn btn-outline">
                    <i class="fas fa-times"></i> Clear Filters
                </a>
            </form>

            <!-- Booking Stats -->
            <div class="dashboard-cards">
                <div class="card" onclick="filterByStatus('all')">
                    <h3>Total Bookings</h3>
                    <p><?= $counts['total'] ?></p>
                </div>
                <div class="card" onclick="filterByStatus('pending')">
                    <h3>Pending</h3>
                    <p><?= $counts['pending'] ?></p>
                </div>
                <div class="card" onclick="filterByStatus('confirmed')">
                    <h3>Confirmed</h3>
                    <p><?= $counts['confirmed'] ?></p>
                </div>
                <div class="card" onclick="filterByStatus('completed')">
                    <h3>Completed</h3>
                    <p><?= $counts['completed'] ?></p>
                </div>
                <div class="card" onclick="filterByStatus('cancelled')">
                    <h3>Cancelled</h3>
                    <p><?= $counts['cancelled'] + $counts['rejected'] ?></p>
                </div>
                <div class="card" onclick="filterByPayment('paid')">
                    <h3>Paid</h3>
                    <p><?= $counts['paid'] ?></p>
                </div>
                <div class="card" onclick="filterByPayment('unpaid')">
                    <h3>Unpaid</h3>
                    <p><?= $counts['unpaid'] ?></p>
                </div>
            </div>

            <!-- Bookings Table -->
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Service</th>
                        <th>Customer</th>
                        <th>Provider</th>
                        <th>Date & Time</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Payment</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($bookings->num_rows > 0): ?>
                        <?php while ($booking = $bookings->fetch_assoc()): 
                            $bookingDate = new DateTime($booking['booking_date']);
                            $date = $bookingDate->format('M d, Y');
                            $time = $bookingDate->format('h:i A');
                        ?>
                            <tr>
                                <td>#<?= $booking['id'] ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <img src="<?= htmlspecialchars($booking['service_image'] ?? 'https://via.placeholder.com/80x60?text=Service') ?>" 
                                             alt="<?= htmlspecialchars($booking['service_name']) ?>" 
                                             class="service-image">
                                        <div>
                                            <div><?= htmlspecialchars($booking['service_name']) ?></div>
                                            <small><?= htmlspecialchars($booking['category_name']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?= htmlspecialchars($booking['customer_name']) ?><br>
                                    <small><?= htmlspecialchars($booking['customer_email']) ?></small><br>
                                    <small><?= htmlspecialchars($booking['customer_phone']) ?></small>
                                </td>
                                <td>
                                    <?= htmlspecialchars($booking['provider_name']) ?><br>
                                    <small><?= htmlspecialchars($booking['provider_phone']) ?></small>
                                </td>
                                <td>
                                    <?= $date ?><br>
                                    <small><?= $time ?></small>
                                </td>
                                <td>₹<?= number_format($booking['amount'], 2) ?></td>
                                <td>
                                    <span class="booking-status status-<?= $booking['status'] ?>">
                                        <?= ucfirst($booking['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="payment-status payment-<?= $booking['payment_status'] ?>">
                                        <?= ucfirst($booking['payment_status']) ?>
                                    </span><br>
                                    <small><?= ucfirst($booking['payment_type']) ?> payment</small>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button onclick="toggleDetails(<?= $booking['id'] ?>)" class="btn-sm btn-outline">
                                            <i class="fas fa-chevron-down"></i> Details
                                        </button>
                                        
                                        <?php if ($booking['status'] === 'pending'): ?>
                                            <button class="btn-sm btn-primary" onclick="showStatusModal(<?= $booking['id'] ?>, 'confirm')">
                                                <i class="fas fa-check"></i> Confirm
                                            </button>
                                            <button class="btn-sm btn-outline" style="color: var(--error); border-color: var(--error);" 
                                                    onclick="showStatusModal(<?= $booking['id'] ?>, 'reject')">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        <?php elseif ($booking['status'] === 'confirmed'): ?>
                                            <button class="btn-sm btn-primary" onclick="showStatusModal(<?= $booking['id'] ?>, 'complete')">
                                                <i class="fas fa-check-circle"></i> Complete
                                            </button>
                                            <button class="btn-sm btn-outline" style="color: var(--error); border-color: var(--error);" 
                                                    onclick="showStatusModal(<?= $booking['id'] ?>, 'cancel')">
                                                <i class="fas fa-times"></i> Cancel
                                            </button>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                            <button type="submit" name="delete_booking" class="btn btn-outline btn-sm" 
                                                    onclick="return confirm('Are you sure you want to delete this booking? This action cannot be undone.');">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                    
                                    <div id="details-<?= $booking['id'] ?>" class="booking-details">
                                        <p><strong>Service Address:</strong> <?= htmlspecialchars($booking['address']) ?></p>
                                        <p><strong>Payment Type:</strong> <?= ucfirst($booking['payment_type']) ?></p>
                                        
                                        <?php if ($booking['rating']): ?>
                                            <p><strong>Rating:</strong> <?= str_repeat('★', $booking['rating']) . str_repeat('☆', 5 - $booking['rating']) ?></p>
                                            <p><strong>Review:</strong> <?= htmlspecialchars($booking['review_comment']) ?></p>
                                        <?php elseif (($booking['status'] === 'cancelled' || $booking['status'] === 'rejected') && !empty($booking['cancellation_reason'])): ?>
                                            <div class="cancellation-reason">
                                                <strong>Reason:</strong>
                                                <p><?= htmlspecialchars($booking['cancellation_reason']) ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 30px;">
                                No bookings found matching your criteria.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="modal-header">
                    <h3 id="modalTitle">Update Booking Status</h3>
                    <span class="close-modal" onclick="closeModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="booking_id" id="modalBookingId">
                    <input type="hidden" name="new_status" id="modalNewStatus">
                    
                    <div id="statusMessage"></div>
                    
                    <div class="form-group" id="paymentStatusGroup" style="display: none;">
                        <label for="modalPaymentStatus">Payment Status:</label>
                        <select name="payment_status" id="modalPaymentStatus" class="form-control">
                            <option value="paid">Paid</option>
                            <option value="pending">Pending</option>
                            <option value="unpaid">Unpaid</option>
                        </select>
                    </div>
                    
                    <label for="statusNotes" id="statusNotesLabel">Notes:</label>
                    <textarea id="statusNotes" name="notes" class="modal-textarea" placeholder="Add any notes about this status change..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                    <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Toggle booking details
        function toggleDetails(bookingId) {
            const details = document.getElementById(`details-${bookingId}`);
            details.style.display = details.style.display === 'block' ? 'none' : 'block';
        }

        // Filter by status when clicking on stats cards
        function filterByStatus(status) {
            const url = new URL(window.location.href);
            if (status !== 'all') {
                url.searchParams.set('status', status);
            } else {
                url.searchParams.delete('status');
            }
            // Reset other filters
            url.searchParams.delete('date');
            url.searchParams.delete('payment_status');
            window.location.href = url.toString();
        }

        // Filter by payment status when clicking on stats cards
        function filterByPayment(status) {
            const url = new URL(window.location.href);
            if (status !== 'all') {
                url.searchParams.set('payment_status', status);
            } else {
                url.searchParams.delete('payment_status');
            }
            // Reset other filters
            url.searchParams.delete('date');
            url.searchParams.delete('status');
            window.location.href = url.toString();
        }

        // Status modal handling
        function showStatusModal(bookingId, action) {
            const modal = document.getElementById('statusModal');
            const title = document.getElementById('modalTitle');
            const message = document.getElementById('statusMessage');
            const bookingIdInput = document.getElementById('modalBookingId');
            const statusInput = document.getElementById('modalNewStatus');
            const notesLabel = document.getElementById('statusNotesLabel');
            const notesTextarea = document.getElementById('statusNotes');
            const paymentStatusGroup = document.getElementById('paymentStatusGroup');
            
            // Set action-specific values
            let actionText = '';
            let statusValue = '';
            let messageText = '';
            let showPaymentStatus = false;
            let notesRequired = false;
            
            switch(action) {
                case 'confirm':
                    actionText = 'Confirm Booking';
                    statusValue = 'confirmed';
                    messageText = 'Are you sure you want to confirm this booking?';
                    notesLabel.textContent = 'Notes (Optional):';
                    notesRequired = false;
                    showPaymentStatus = true;
                    break;
                case 'complete':
                    actionText = 'Complete Booking';
                    statusValue = 'completed';
                    messageText = 'Mark this booking as completed?';
                    notesLabel.textContent = 'Notes (Optional):';
                    notesRequired = false;
                    showPaymentStatus = true;
                    break;
                case 'cancel':
                    actionText = 'Cancel Booking';
                    statusValue = 'cancelled';
                    messageText = 'Are you sure you want to cancel this booking? Please provide a reason.';
                    notesLabel.textContent = 'Reason for cancellation (Required):';
                    notesRequired = true;
                    showPaymentStatus = false;
                    break;
                case 'reject':
                    actionText = 'Reject Booking';
                    statusValue = 'rejected';
                    messageText = 'Are you sure you want to reject this booking? Please provide a reason.';
                    notesLabel.textContent = 'Reason for rejection (Required):';
                    notesRequired = true;
                    showPaymentStatus = false;
                    break;
            }
            
            title.textContent = actionText;
            bookingIdInput.value = bookingId;
            statusInput.value = statusValue;
            message.innerHTML = `<p>${messageText}</p>`;
            notesTextarea.value = ''; // Clear previous notes
            notesTextarea.required = notesRequired;
            paymentStatusGroup.style.display = showPaymentStatus ? 'block' : 'none';
            
            modal.style.display = 'block';
        }

        function closeModal() {
            document.getElementById('statusModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                closeModal();
            }
        }

        // Initialize details toggles
        document.addEventListener('DOMContentLoaded', function() {
            // Close all details by default
            document.querySelectorAll('.booking-details').forEach(detail => {
                detail.style.display = 'none';
            });
        });
    </script>
</body>
</html>