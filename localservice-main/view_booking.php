<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

include 'db.php';

$booking_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$booking_id) {
    header("Location: " . ($_SESSION['user']['role'] === 'admin' ? 'admin_dashboard.php' : 'customer_bookings.php'));
    exit;
}

// Check if user has permission to view this booking
$query = "SELECT b.*, 
                 s.name AS service_name, 
                 s.image AS service_image,
                 s.description AS service_description,
                 u_provider.name AS provider_name,
                 u_provider.phone AS provider_phone,
                 u_customer.name AS customer_name,
                 u_customer.email AS customer_email,
                 u_customer.phone AS customer_phone,
                 b.cancellation_reason,
                 (SELECT image_url FROM service_images WHERE service_id = s.id LIMIT 1) AS featured_image
          FROM bookings b
          JOIN services s ON b.service_id = s.id
          JOIN users u_provider ON b.provider_id = u_provider.id
          JOIN users u_customer ON b.user_id = u_customer.id
          WHERE b.id = ?";

// Add role-based permission check
if ($_SESSION['user']['role'] === 'customer') {
    $query .= " AND b.user_id = ?";
    $params = [$booking_id, $_SESSION['user']['id']];
    $types = "ii";
} elseif ($_SESSION['user']['role'] === 'provider') {
    $query .= " AND b.provider_id = ?";
    $params = [$booking_id, $_SESSION['user']['id']];
    $types = "ii";
} else {
    // Admin can view any booking
    $params = [$booking_id];
    $types = "i";
}

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    header("Location: " . ($_SESSION['user']['role'] === 'admin' ? 'admin_dashboard.php' : 'customer_bookings.php'));
    exit;
}

// Format dates
$booking_date = date('M d, Y h:i A', strtotime($booking['booking_date']));
$created_at = date('M d, Y h:i A', strtotime($booking['created_at']));

// Determine which image to display (priority: featured_image > service_image > fallback)
$service_image = $booking['featured_image'] ?: ($booking['service_image'] ?: 'https://via.placeholder.com/600x400?text=Service');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Details | UrbanServe</title>
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

        .container {
            max-width: 1200px;
            margin: 0 auto;
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

        .booking-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        .booking-image {
            width: 100%;
            height: 300px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .booking-details {
            background-color: var(--white);
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .booking-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border);
        }

        .booking-service {
            font-size: 22px;
            font-weight: 600;
            color: var(--secondary);
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }

        .status-pending {
            background-color: rgba(237, 137, 54, 0.1);
            color: var(--warning);
        }

        .status-confirmed {
            background-color: rgba(56, 161, 105, 0.1);
            color: var(--success);
        }

        .status-completed {
            background-color: rgba(66, 153, 225, 0.1);
            color: #4299e1;
        }

        .status-cancelled {
            background-color: rgba(229, 62, 62, 0.1);
            color: var(--error);
        }

        .detail-group {
            margin-bottom: 15px;
        }

        .detail-group label {
            display: block;
            font-size: 14px;
            color: var(--light-text);
            margin-bottom: 5px;
        }

        .detail-group p {
            font-size: 16px;
            color: var(--text);
        }

        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .rejection-reason {
            margin-top: 20px;
            padding: 15px;
            background-color: #fff5f5;
            border-left: 3px solid var(--error);
            border-radius: 4px;
        }

        .rejection-reason strong {
            color: var(--error);
            display: block;
            margin-bottom: 8px;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }

        .notes-section {
            margin-top: 25px;
            padding: 20px;
            background-color: var(--accent);
            border-radius: 8px;
        }

        .notes-section h3 {
            margin-bottom: 15px;
            color: var(--secondary);
        }

        .notes-content {
            background-color: var(--white);
            padding: 15px;
            border-radius: 6px;
            border: 1px solid var(--border);
        }

        @media (max-width: 768px) {
            .booking-container {
                grid-template-columns: 1fr;
            }
            
            .booking-image {
                height: 200px;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Booking Details</h1>
            <a href="<?= $_SESSION['user']['role'] === 'admin' ? 'admin_dashboard.php' : 'customer_bookings.php' ?>" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back to Bookings
            </a>
        </div>

        <div class="booking-container">
            <div>
                <img src="<?= htmlspecialchars($service_image) ?>" 
                     alt="<?= htmlspecialchars($booking['service_name']) ?>" 
                     class="booking-image"
                     onerror="this.src='https://via.placeholder.com/600x400?text=Service'">
                
                <div class="booking-details">
                    <h2 class="booking-service"><?= htmlspecialchars($booking['service_name']) ?></h2>
                    <p style="margin-bottom: 20px; color: var(--light-text);"><?= htmlspecialchars($booking['service_description']) ?></p>
                    
                    <div class="detail-grid">
                        <div class="detail-group">
                            <label>Booking ID</label>
                            <p>#<?= $booking['id'] ?></p>
                        </div>
                        <div class="detail-group">
                            <label>Status</label>
                            <p>
                                <span class="status-badge status-<?= strtolower($booking['status']) ?>">
                                    <?= ucfirst($booking['status']) ?>
                                    <?php if ($booking['status'] === 'cancelled' && !empty($booking['cancellation_reason'])): ?>
                                        (Rejected)
                                    <?php endif; ?>
                                </span>
                            </p>
                        </div>
                        <div class="detail-group">
                            <label>Date & Time</label>
                            <p><?= $booking_date ?></p>
                        </div>
                        <div class="detail-group">
                            <label>Amount</label>
                            <p>₹<?= number_format($booking['amount'], 2) ?></p>
                        </div>
                        <div class="detail-group">
                            <label>Payment Status</label>
                            <p><?= ucfirst($booking['payment_status']) ?></p>
                        </div>
                        <div class="detail-group">
                            <label>Payment Method</label>
                            <p><?= ucfirst($booking['payment_type']) ?></p>
                        </div>
                    </div>
                    
                    <?php if ($booking['status'] === 'cancelled' && !empty($booking['cancellation_reason'])): ?>
                        <div class="rejection-reason">
                            <strong>Reason for rejection:</strong>
                            <p><?= htmlspecialchars($booking['cancellation_reason']) ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="action-buttons">
                        <?php if ($_SESSION['user']['role'] === 'admin' || $_SESSION['user']['role'] === 'provider'): ?>
                            <?php if ($booking['status'] === 'pending'): ?>
                                <a href="update_booking_status.php?id=<?= $booking['id'] ?>&status=confirmed" class="btn btn-primary">
                                    <i class="fas fa-check"></i> Confirm Booking
                                </a>
                                <a href="update_booking_status.php?id=<?= $booking['id'] ?>&status=cancelled" class="btn btn-outline" style="color: var(--error); border-color: var(--error);">
                                    <i class="fas fa-times"></i> Reject Booking
                                </a>
                            <?php elseif ($booking['status'] === 'confirmed'): ?>
                                <a href="update_booking_status.php?id=<?= $booking['id'] ?>&status=completed" class="btn btn-primary">
                                    <i class="fas fa-check-circle"></i> Mark as Completed
                                </a>
                            <?php endif; ?>
                        <?php elseif ($_SESSION['user']['role'] === 'customer'): ?>
                            <?php if ($booking['status'] === 'pending' || $booking['status'] === 'confirmed'): ?>
                                <a href="cancel_booking.php?id=<?= $booking['id'] ?>" class="btn btn-outline" style="color: var(--error); border-color: var(--error);" onclick="return confirm('Are you sure you want to cancel this booking?')">
                                    <i class="fas fa-times"></i> Cancel Booking
                                </a>
                            <?php elseif ($booking['status'] === 'completed'): ?>
                                <a href="rate_service.php?booking_id=<?= $booking['id'] ?>" class="btn btn-primary">
                                    <i class="fas fa-star"></i> Rate Service
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div>
                <!-- Provider/Customer Details -->
                <div class="booking-details">
                    <h2 style="margin-bottom: 20px;"><?= $_SESSION['user']['role'] === 'admin' ? 'Booking Parties' : ($_SESSION['user']['role'] === 'customer' ? 'Provider Details' : 'Customer Details') ?></h2>
                    
                    <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                        <div style="margin-bottom: 25px;">
                            <h3 style="font-size: 18px; margin-bottom: 15px; color: var(--secondary);">Customer Information</h3>
                            <div class="detail-grid">
                                <div class="detail-group">
                                    <label>Name</label>
                                    <p><?= htmlspecialchars($booking['customer_name']) ?></p>
                                </div>
                                <div class="detail-group">
                                    <label>Email</label>
                                    <p><?= htmlspecialchars($booking['customer_email']) ?></p>
                                </div>
                                <div class="detail-group">
                                    <label>Phone</label>
                                    <p><?= htmlspecialchars($booking['customer_phone']) ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <h3 style="font-size: 18px; margin-bottom: 15px; color: var(--secondary);">Provider Information</h3>
                            <div class="detail-grid">
                                <div class="detail-group">
                                    <label>Name</label>
                                    <p><?= htmlspecialchars($booking['provider_name']) ?></p>
                                </div>
                                <div class="detail-group">
                                    <label>Phone</label>
                                    <p><?= htmlspecialchars($booking['provider_phone']) ?></p>
                                </div>
                            </div>
                        </div>
                    <?php elseif ($_SESSION['user']['role'] === 'customer'): ?>
                        <div class="detail-grid">
                            <div class="detail-group">
                                <label>Provider Name</label>
                                <p><?= htmlspecialchars($booking['provider_name']) ?></p>
                            </div>
                            <div class="detail-group">
                                <label>Provider Phone</label>
                                <p><?= htmlspecialchars($booking['provider_phone']) ?></p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="detail-grid">
                            <div class="detail-group">
                                <label>Customer Name</label>
                                <p><?= htmlspecialchars($booking['customer_name']) ?></p>
                            </div>
                            <div class="detail-group">
                                <label>Customer Email</label>
                                <p><?= htmlspecialchars($booking['customer_email']) ?></p>
                            </div>
                            <div class="detail-group">
                                <label>Customer Phone</label>
                                <p><?= htmlspecialchars($booking['customer_phone']) ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Booking Notes -->
                <?php if (!empty($booking['customer_notes']) || !empty($booking['provider_notes']) || !empty($booking['admin_notes'])): ?>
                    <div class="notes-section">
                        <h3>Booking Notes</h3>
                        <div class="notes-content">
                            <?php if (!empty($booking['customer_notes'])): ?>
                                <div style="margin-bottom: 15px;">
                                    <strong>Customer Notes:</strong>
                                    <p><?= htmlspecialchars($booking['customer_notes']) ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($booking['provider_notes'])): ?>
                                <div style="margin-bottom: 15px;">
                                    <strong>Provider Notes:</strong>
                                    <p><?= htmlspecialchars($booking['provider_notes']) ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($booking['admin_notes']) && $_SESSION['user']['role'] === 'admin'): ?>
                                <div>
                                    <strong>Admin Notes:</strong>
                                    <p><?= htmlspecialchars($booking['admin_notes']) ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Additional Booking Info -->
                <div class="booking-details" style="margin-top: 20px;">
                    <h3 style="margin-bottom: 15px;">Additional Information</h3>
                    <div class="detail-grid">
                        <div class="detail-group">
                            <label>Booking Created</label>
                            <p><?= $created_at ?></p>
                        </div>
                        <div class="detail-group">
                            <label>Service Address</label>
                            <p><?= htmlspecialchars($booking['address']) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>