<?php
session_start();
if ($_SESSION['user']['role'] !== 'customer') {
    header("Location: login.php");
    exit;
}

include 'db.php';

$customer_id = $_SESSION['user']['id'];

// Fetch customer data
$customer_sql = "SELECT name, email, phone, address, city, state, pincode, created_at FROM users WHERE id = ?";
$customer_stmt = $conn->prepare($customer_sql);
$customer_stmt->bind_param("i", $customer_id);
$customer_stmt->execute();
$customer_result = $customer_stmt->get_result();
$customer = $customer_result->fetch_assoc();

// Upcoming Bookings
$upcomingBookings = $conn->query("
    SELECT COUNT(*) FROM bookings 
    WHERE user_id = $customer_id 
      AND status IN ('pending', 'confirmed') 
      AND booking_date >= NOW()
")->fetch_row()[0];

// Completed Services
$completedServices = $conn->query("
    SELECT COUNT(*) FROM bookings 
    WHERE user_id = $customer_id 
      AND status = 'completed'
")->fetch_row()[0];

// Favorite Providers (from reviews)
$favorite_sql = "
    SELECT COUNT(DISTINCT b.provider_id)
    FROM reviews r
    JOIN bookings b ON r.booking_id = b.id
    WHERE b.user_id = ? AND r.rating >= 4
";
$favorite_stmt = $conn->prepare($favorite_sql);
$favorite_stmt->bind_param("i", $customer_id);
$favorite_stmt->execute();
$favorite_result = $favorite_stmt->get_result();
$favoriteProviders = $favorite_result->fetch_row()[0];

// Recent Bookings - Updated to include cancellation_reason
$bookings_sql = "
    SELECT 
        b.*, 
        s.name AS service_name, 
        u.name AS provider_name,
        b.cancellation_reason
    FROM bookings b
    JOIN services s ON b.service_id = s.id
    JOIN users u ON b.provider_id = u.id
    WHERE b.user_id = ?
    ORDER BY b.booking_date DESC
    LIMIT 5
";
$bookings_stmt = $conn->prepare($bookings_sql);
$bookings_stmt->bind_param("i", $customer_id);
$bookings_stmt->execute();
$bookings_result = $bookings_stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard | UrbanServe</title>
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

        /* Sidebar Styles */
        .sidebar {
            width: 250px;
            background-color: var(--secondary);
            color: var(--white);
            padding: 20px 0;
            position: sticky;
            top: 0;
            height: 100vh;
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }

        .profile-img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 10px;
            display: block;
            border: 3px solid var(--primary);
        }

        .sidebar-header h3 {
            color: var(--white);
            margin-bottom: 5px;
        }

        .sidebar-header p {
            color: var(--light-text);
            font-size: 14px;
        }

        .nav-menu {
            padding: 20px 0;
        }

        .nav-item {
            padding: 12px 20px;
            color: #cbd5e0;
            text-decoration: none;
            display: block;
            transition: all 0.3s;
        }

        .nav-item:hover, .nav-item.active {
            background-color: rgba(255,255,255,0.1);
            color: var(--white);
        }

        .nav-item i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
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

        /* Dashboard Cards */
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background-color: var(--white);
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
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

        .card .primary {
            color: var(--primary);
        }

        /* Profile Section */
        .profile-section {
            background-color: var(--white);
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border);
        }

        .section-header h2 {
            font-size: 20px;
        }

        .profile-details {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
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
            padding: 8px 12px;
            background-color: var(--accent);
            border-radius: 5px;
        }

        /* Bookings Section */
        .bookings-section {
            background-color: var(--white);
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .booking-card {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s;
        }

        .booking-card:hover {
            border-color: var(--primary);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .booking-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .booking-service {
            font-weight: 600;
            color: var(--secondary);
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-pending {
            background-color: #f6e05e;
            color: #975a16;
        }

        .status-confirmed {
            background-color: #68d391;
            color: #1f6521;
        }

        .status-completed {
            background-color: #a0aec0;
            color: #2d3748;
        }

        .status-cancelled {
            background-color: #fc8181;
            color: #9b2c2c;
        }

        .status-rejected {
            background-color: #feb2b2;
            color: #9b2c2c;
        }

        .booking-date {
            display: block;
            color: var(--light-text);
            font-size: 14px;
            margin-bottom: 15px;
        }

        .booking-details {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }

        .booking-detail label {
            display: block;
            font-size: 12px;
            color: var(--light-text);
            margin-bottom: 5px;
        }

        .booking-detail p {
            font-size: 15px;
            color: var(--text);
        }

        .rejection-reason {
            margin-top: 10px;
            padding: 10px;
            background-color: #fff5f5;
            border-left: 3px solid var(--error);
            border-radius: 4px;
        }

        .rejection-reason strong {
            color: var(--error);
        }

        .action-link {
            color: var(--primary);
            text-decoration: none;
            margin-right: 15px;
            font-size: 14px;
            transition: all 0.2s;
        }

        .action-link:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .action-link i {
            margin-right: 5px;
        }

        @media (max-width: 768px) {
            .dashboard-container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            
            .booking-details {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

    <div class="dashboard-container">
        <!-- Sidebar Navigation -->
        <div class="sidebar">
            <div class="sidebar-header">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($_SESSION['user']['name']) ?>&background=f76d2b&color=fff" 
                     alt="Profile" class="profile-img">
                <h3><?= htmlspecialchars($_SESSION['user']['name']) ?></h3>
                <p>Customer</p>
            </div>
            
            <div class="nav-menu">
                <a href="index.php" class="nav-item">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="#" class="nav-item active">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="#profile" class="nav-item">
                    <i class="fas fa-user"></i> My Profile
                </a>
                <a href="customer_bookings.php" class="nav-item">
                    <i class="fas fa-calendar-check"></i> My Bookings
                </a>
                <a href="services.php" class="nav-item">
                    <i class="fas fa-concierge-bell"></i> Book Services
                </a>
                  <a href="usercontacted.php" class="nav-item">
                    <i class="fas fa-envelope"></i> Feedback Messages
                </a>
                <a href="logout.php" class="nav-item">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="main-content">
            <div class="header">
                <h1>Customer Dashboard</h1>
                <div class="user-actions">
                    <span>Welcome back, <?= htmlspecialchars($_SESSION['user']['name']) ?></span>
                    <a href="logout.php" class="btn btn-outline">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>

            <!-- Dashboard Stats -->
            <div class="dashboard-cards">
                <div class="card">
                    <h3>Upcoming Bookings</h3>
                    <p><?= $upcomingBookings ?></p>
                </div>
                <div class="card">
                    <h3>Completed Services</h3>
                    <p><?= $completedServices ?></p>
                </div>
                <div class="card">
                    <h3>Favorite Providers</h3>
                    <p><?= $favoriteProviders ?></p>
                </div>
            </div>

            <!-- Profile Section -->
            <div id="profile" class="profile-section">
                <div class="section-header">
                    <h2>My Profile</h2>
                    <a href="edit_profile.php" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit Profile
                    </a>
                </div>
                
                <div class="profile-details">
                    <div>
                        <div class="detail-group">
                            <label>Full Name</label>
                            <p><?= htmlspecialchars($customer['name']) ?></p>
                        </div>
                        <div class="detail-group">
                            <label>Email</label>
                            <p><?= htmlspecialchars($customer['email']) ?></p>
                        </div>
                        <div class="detail-group">
                            <label>Phone</label>
                            <p><?= htmlspecialchars($customer['phone']) ?></p>
                        </div>
                        <div class="detail-group">
                            <label>City</label>
                            <p><?= htmlspecialchars($customer['city']) ?></p>
                        </div>
                    </div>
                    <div>
                        <div class="detail-group">
                            <label>State</label>
                            <p><?= htmlspecialchars($customer['state']) ?></p>
                        </div>
                        <div class="detail-group">
                            <label>Pincode</label>
                            <p><?= htmlspecialchars($customer['pincode']) ?></p>
                        </div>
                        <div class="detail-group">
                            <label>Address</label>
                            <p><?= htmlspecialchars($customer['address']) ?></p>
                        </div>
                        <div class="detail-group">
                            <label>Member Since</label>
                            <p><?= date('M d, Y', strtotime($customer['created_at'])) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            
        </div>
    </div>
</body>
</html>