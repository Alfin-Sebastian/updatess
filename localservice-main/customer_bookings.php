<?php
session_start();
if ($_SESSION['user']['role'] !== 'customer') {
    header("Location: login.php");
    exit;
}

include 'db.php';

$customer_id = $_SESSION['user']['id'];

// Handle cancellation via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    $booking_id = intval($_POST['booking_id']);
    $reason = trim($_POST['reason'] ?? 'Customer requested cancellation');
    
    // Update booking status
    $stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled', cancellation_reason = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param("sii", $reason, $booking_id, $customer_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
    exit;
}

// Fetch customer data
$customer_sql = "SELECT name, email FROM users WHERE id = ?";
$customer_stmt = $conn->prepare($customer_sql);
$customer_stmt->bind_param("i", $customer_id);
$customer_stmt->execute();
$customer_result = $customer_stmt->get_result();
$customer = $customer_result->fetch_assoc();

// Handle filters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';

// Build query with prepared statements
$query = "
    SELECT 
        b.*, 
        s.name AS service_name, 
        s.image AS service_image,
        u.name AS provider_name,
        b.cancellation_reason
    FROM bookings b
    JOIN services s ON b.service_id = s.id
    JOIN users u ON b.provider_id = u.id
    WHERE b.user_id = ?
";

$where = [];
$params = [$customer_id];
$types = "i";

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

if (!empty($where)) {
    $query .= " AND " . implode(" AND ", $where);
}

$query .= " ORDER BY b.booking_date DESC";

// Execute query with prepared statement
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$bookings = $stmt->get_result();

// Get counts for dashboard
$counts = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(status = 'pending') as pending,
        SUM(status = 'confirmed') as confirmed,
        SUM(status = 'completed') as completed,
        SUM(status = 'cancelled') as cancelled
    FROM bookings
    WHERE user_id = $customer_id
")->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings | UrbanServe</title>
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

        /* Dashboard Cards */
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background-color: var(--white);
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            text-align: center;
            cursor: pointer;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card h3 {
            font-size: 14px;
            color: var(--light-text);
            margin-bottom: 10px;
        }

        .stat-card p {
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
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .service-image {
            width: 60px;
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
            .dashboard-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .data-table {
                display: block;
                overflow-x: auto;
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
                <h1>My Bookings</h1>
                <a href="customer_dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <!-- Filter Controls -->
            <div class="filter-controls">
                <form method="GET" class="row">
                    <div class="filter-group">
                        <label for="status">Status:</label>
                        <select name="status" id="status">
                            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="confirmed" <?= $status_filter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                            <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    
                    
                 
                        <label for="date">Date:</label>
                        <input type="date" name="date" id="date" value="<?= htmlspecialchars($date_filter) ?>">
               
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="customer_bookings.php" class="btn btn-outline">
                        <i class="fas fa-times"></i> Clear Filters
                    </a>
                       </div>
                </form>
            </div>

            <!-- Booking Stats -->
            <div class="dashboard-cards">
                <div class="stat-card" onclick="filterByStatus('all')">
                    <h3>Total Bookings</h3>
                    <p><?= $counts['total'] ?></p>
                </div>
                <div class="stat-card" onclick="filterByStatus('pending')">
                    <h3>Pending</h3>
                    <p><?= $counts['pending'] ?></p>
                </div>
                <div class="stat-card" onclick="filterByStatus('confirmed')">
                    <h3>Confirmed</h3>
                    <p><?= $counts['confirmed'] ?></p>
                </div>
                <div class="stat-card" onclick="filterByStatus('completed')">
                    <h3>Completed</h3>
                    <p><?= $counts['completed'] ?></p>
                </div>
                <div class="stat-card" onclick="filterByStatus('cancelled')">
                    <h3>Cancelled</h3>
                    <p><?= $counts['cancelled'] ?></p>
                </div>
            </div>

            <!-- Bookings Table -->
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Provider</th>
                        <th>Date & Time</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($bookings->num_rows > 0): ?>
                        <?php while ($booking = $bookings->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <img src="<?= htmlspecialchars($booking['service_image'] ?? 'https://via.placeholder.com/60?text=Service') ?>" 
                                             alt="<?= htmlspecialchars($booking['service_name']) ?>" 
                                             class="service-image">
                                        <div>
                                            <div><?= htmlspecialchars($booking['service_name']) ?></div>
                                            <small><?= date('M d, Y', strtotime($booking['booking_date'])) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($booking['provider_name']) ?></td>
                                <td><?= date('h:i A', strtotime($booking['booking_date'])) ?></td>
                                <td>₹<?= number_format($booking['amount'], 2) ?></td>
                                <td>
                                    <span class="booking-status status-<?= strtolower($booking['status']) ?>">
                                        <?= ucfirst($booking['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="view_booking.php?id=<?= $booking['id'] ?>" class="btn btn-primary">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($booking['status'] === 'pending' || $booking['status'] === 'confirmed'): ?>
                                            <button class="btn btn-outline cancel-btn" 
                                                    style="color: var(--error); border-color: var(--error);"
                                                    onclick="showCancelModal(<?= $booking['id'] ?>)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($booking['status'] === 'completed'): ?>
                                            <a href="rate_service.php?booking_id=<?= $booking['id'] ?>" class="btn btn-primary">
                                                <i class="fas fa-star"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 30px;">
                                <i class="fas fa-calendar-times fa-2x" style="color: var(--light-text); margin-bottom: 10px;"></i>
                                <p>No bookings found matching your criteria.</p>
                                <a href="services.php" class="btn btn-primary">
                                    <i class="fas fa-concierge-bell"></i> Book a Service
                                </a>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Cancel Booking Modal -->
    <div id="cancelModal" class="modal">
        <div class="modal-content">
            <form id="cancelForm">
                <div class="modal-header">
                    <h3>Cancel Booking</h3>
                    <span class="close-modal" onclick="closeModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="booking_id" id="modalBookingId">
                    <p>Are you sure you want to cancel this booking?</p>
                    <label for="cancelReason">Reason (optional):</label>
                    <textarea id="cancelReason" name="reason" class="modal-textarea" placeholder="Please specify reason for cancellation"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal()">Close</button>
                    <button type="submit" class="btn btn-primary" style="background-color: var(--error); border-color: var(--error);">Confirm Cancellation</button>
                </div>
            </form>
        </div>
    </div>

    <script>
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
            window.location.href = url.toString();
        }

        // Cancel modal handling
        function showCancelModal(bookingId) {
            const modal = document.getElementById('cancelModal');
            document.getElementById('modalBookingId').value = bookingId;
            modal.style.display = 'block';
        }

        function closeModal() {
            document.getElementById('cancelModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                closeModal();
            }
        }

        // Handle form submission
        document.getElementById('cancelForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeModal();
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Failed to cancel booking'));
                }
            })
            .catch(error => {
                alert('An error occurred. Please try again.');
            });
        });
    </script>
</body>
</html>