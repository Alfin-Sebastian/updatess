<?php
session_start();
if ($_SESSION['user']['role'] !== 'provider') {
    header("Location: login.php");
    exit;
}

include 'db.php';

$provider_id = $_SESSION['user']['id'];

// Fetch profile details with prepared statement
$profile_sql = "SELECT u.name, u.email, u.phone, u.city, u.state, u.pincode, u.profile_image, 
                       p.experience, p.bio, p.location, p.id_proof, p.is_verified, 
                       p.verification_status, p.verification_notes 
                FROM users u 
                JOIN providers p ON u.id = p.user_id 
                WHERE u.id = ?";
$profile_stmt = $conn->prepare($profile_sql);
$profile_stmt->bind_param("i", $provider_id);
$profile_stmt->execute();
$profile_result = $profile_stmt->get_result();
$profile = $profile_result->fetch_assoc();

// Check verification status
$verification_status = $profile['verification_status'] ?? 'unverified';
$is_verified = ($verification_status === 'approved');

// Fetch stats
$totalBookings = $conn->query("SELECT COUNT(*) FROM bookings WHERE provider_id = $provider_id")->fetch_row()[0];
$upcomingJobs = $conn->query("SELECT COUNT(*) FROM bookings WHERE provider_id = $provider_id AND status = 'confirmed' AND booking_date >= NOW()")->fetch_row()[0];
$totalEarnings = $conn->query("SELECT SUM(amount) FROM bookings WHERE provider_id = $provider_id AND status IN ('confirmed', 'completed')")->fetch_row()[0] ?? 0;
$rating = $conn->query("SELECT AVG(rating) FROM reviews WHERE provider_id = $provider_id")->fetch_row()[0];

// Fetch services with prepared statement (only if verified)
$services_result = null;
if ($is_verified) {
    $services_sql = "SELECT s.*, ps.price, 
                    (SELECT image_url FROM service_images WHERE service_id = s.id LIMIT 1) as primary_image
                    FROM services s 
                    JOIN provider_services ps ON s.id = ps.service_id 
                    WHERE ps.provider_id = ?";
    $services_stmt = $conn->prepare($services_sql);
    $services_stmt->bind_param("i", $provider_id);
    $services_stmt->execute();
    $services_result = $services_stmt->get_result();
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
    <title>Provider Dashboard | UrbanClap</title>
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
            --warning: #dd6b20;
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

        .profile-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border);
        }

        .profile-header h2 {
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

        /* Services Section */
        .services-section {
            background-color: var(--white);
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .service-card {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 15px;
            transition: all 0.3s;
        }

        .service-card:hover {
            border-color: var(--primary);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .service-card-img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 6px;
            margin-bottom: 10px;
        }

        .service-card h4 {
            margin-bottom: 10px;
            color: var(--secondary);
        }

        .service-card p {
            font-size: 14px;
            color: var(--light-text);
            margin-bottom: 10px;
        }

        .service-price {
            font-weight: 600;
            color: var(--primary);
        }

        /* Verification Status */
        .verification-status {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            border-left: 4px solid;
        }
        
        .verified {
            background-color: rgba(56, 161, 105, 0.1);
            color: var(--success);
            border-left-color: var(--success);
        }
        
        .unverified {
            background-color: rgba(229, 62, 62, 0.1);
            color: var(--error);
            border-left-color: var(--error);
        }
        
        .pending {
            background-color: rgba(237, 137, 54, 0.1);
            color: var(--warning);
            border-left-color: var(--warning);
        }
        
        .rejected {
            background-color: rgba(229, 62, 62, 0.1);
            color: var(--error);
            border-left-color: var(--error);
        }
        
        .verification-status i {
            font-size: 24px;
        }
        
        .verification-status div {
            flex: 1;
        }
        
        .verification-status p {
            margin-top: 5px;
            font-size: 14px;
            color: inherit;
        }
        
        .id-proof-container {
            margin-top: 20px;
            border-top: 1px solid var(--border);
            padding-top: 20px;
        }
        
        .id-proof-image {
            max-width: 100%;
            max-height: 300px;
            border: 1px solid var(--border);
            border-radius: 5px;
            margin-top: 10px;
        }
        
        .upload-form {
            margin-top: 15px;
        }
        
        .upload-form input[type="file"] {
            margin-bottom: 10px;
            width: 100%;
        }
        
        .verification-note {
            margin-top: 20px;
            padding: 15px;
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            color: #856404;
            border-radius: 4px;
        }

        /* Messages */
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
            border-left: 4px solid #28a745;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
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
            
            .profile-details {
                grid-template-columns: 1fr;
            }
            
            .verification-status {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar Navigation -->
        <div class="sidebar">
            <div class="sidebar-header">
                <img src="<?= htmlspecialchars($profile['profile_image'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($profile['name']) . '&background=f76d2b&color=fff') ?>" 
                     alt="Profile" class="profile-img">
                <h3><?= htmlspecialchars($_SESSION['user']['name']) ?></h3>
                <p>Service Provider</p>
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
                <?php if ($is_verified): ?>
                <a href="#services" class="nav-item">
                    <i class="fas fa-concierge-bell"></i> My Services
                </a>
                <?php endif; ?>
                <a href="provider_bookings.php" class="nav-item">
                    <i class="fas fa-calendar-check"></i> Bookings
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
                <h1>Provider Dashboard</h1>
                <div class="user-actions">
                    <span>Welcome back, <?= htmlspecialchars($_SESSION['user']['name']) ?></span>
                    <a href="logout.php" class="btn btn-outline">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>

            <!-- Display messages -->
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

      <!-- Verification Status Banner -->
<?php if ($verification_status === 'pending'): ?>
    <div class="verification-status pending">
        <i class="fas fa-clock"></i>
        <div>
            <span>Your verification is under review</span>
            <p>We're currently reviewing your submitted documents. This usually takes 24-48 hours.</p>
        </div>
    </div>
<?php elseif ($verification_status === 'rejected'): ?>
    <div class="verification-status rejected">
        <i class="fas fa-times-circle"></i>
        <div>
            <span>Your verification was rejected</span>
            <p>Reason: <?= htmlspecialchars($profile['verification_notes'] ?? 'No reason specified by admin') ?></p>

            <p>Please update your documents and resubmit for verification.</p>
        </div>
    </div>
<?php elseif ($verification_status === 'approved'): ?>
    <div class="verification-status verified">
        <i class="fas fa-check-circle"></i>
        <div>
            <span>Your account is verified and active!</span>
            <p>You can now add services and accept bookings.</p>
        </div>
    </div>
<?php else: ?>
    <div class="verification-status unverified">
        <i class="fas fa-exclamation-circle"></i>
        <div>
            <span>Account verification required</span>
            <p>Please upload your ID proof to get verified and access all features.</p>
        </div>
    </div>
<?php endif; ?>

            <!-- Dashboard Stats -->
            <div class="dashboard-cards">
                <div class="card">
                    <h3>Total Bookings</h3>
                    <p><?= $totalBookings ?></p>
                </div>
                <div class="card">
                    <h3>Upcoming Jobs</h3>
                    <p><?= $upcomingJobs ?></p>
                </div>
                <div class="card">
                    <h3>Earnings</h3>
                    <p class="primary">₹<?= number_format($totalEarnings, 2) ?></p>
                </div>
                <div class="card">
                    <h3>Rating</h3>
                    <p><?= $rating ? number_format($rating, 1) . ' ★' : 'N/A' ?></p>
                </div>
            </div>

            <!-- Profile Section -->
            <div id="profile" class="profile-section">
                <div class="profile-header">
                    <h2>My Profile</h2>
                    <a href="edit_profile.php" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit Profile
                    </a>
                </div>
                
                <div class="profile-details">
                    <div>
                        <div class="detail-group">
                            <label>Full Name</label>
                            <p><?= htmlspecialchars($profile['name'] ?? 'Not provided') ?></p>
                        </div>
                        <div class="detail-group">
                            <label>Email</label>
                            <p><?= htmlspecialchars($profile['email'] ?? 'Not provided') ?></p>
                        </div>
                        <div class="detail-group">
                            <label>Phone</label>
                            <p><?= htmlspecialchars($profile['phone'] ?? 'Not provided') ?></p>
                        </div>
                        <div class="detail-group">
                            <label>Location</label>
                            <p>
                                <?= !empty($profile['city']) || !empty($profile['state']) 
                                    ? htmlspecialchars(($profile['city'] ?? '') . ', ' . ($profile['state'] ?? '')) 
                                    : 'Not provided' ?>
                            </p>
                        </div>
                    </div>
                    <div>
                        <div class="detail-group">
                            <label>City</label>
                            <p><?= htmlspecialchars($profile['city'] ?? 'Not provided') ?></p>
                        </div>
                        <div class="detail-group">
                            <label>State</label>
                            <p><?= htmlspecialchars($profile['state'] ?? 'Not provided') ?></p>
                        </div>
                        <div class="detail-group">
                            <label>Pincode</label>
                            <p><?= htmlspecialchars($profile['pincode'] ?? 'Not provided') ?></p>
                        </div>
                        <div class="detail-group">
                            <label>Experience</label>
                            <p><?= htmlspecialchars($profile['experience'] ?? 'Not provided') ?></p>
                        </div>
                        <div class="detail-group">
                            <label>Bio</label>
                            <p><?= htmlspecialchars($profile['bio'] ?? 'Not provided') ?></p>
                        </div>
                    </div>
                </div>

                <!-- ID Proof Section -->
                <div class="id-proof-container">
                    <h3>ID Proof Document</h3>
                    <?php if (!empty($profile['id_proof'])): ?>
                        <?php if ($verification_status === 'rejected'): ?>
                            <div class="verification-note">
                                <p><i class="fas fa-info-circle"></i> Your previous submission was rejected. Please upload a new ID proof to try again.</p>
                            </div>
                        <?php elseif ($verification_status === 'pending'): ?>
                            <p>Your submitted ID proof is under review:</p>
                            <img src="<?= htmlspecialchars($profile['id_proof']) ?>" alt="ID Proof" class="id-proof-image">
                        <?php else: ?>
                            <p>Your verified ID proof:</p>
                            <img src="<?= htmlspecialchars($profile['id_proof']) ?>" alt="ID Proof" class="id-proof-image">
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($verification_status !== 'approved'): ?>
                        <form class="upload-form" action="upload_id_proof.php" method="post" enctype="multipart/form-data">
                            <input type="file" name="id_proof" accept="image/*,.pdf" required>
                            <button type="submit" class="btn btn-primary" style="margin-top: 10px;">
                                <i class="fas fa-upload"></i> 
                                <?= !empty($profile['id_proof']) ? 'Update ID Proof' : 'Upload ID Proof' ?>
                            </button>
                        </form>
                        <div class="verification-note">
                            <p><strong>Note:</strong> Accepted formats: JPG, PNG, PDF (Max 5MB)</p>
                            <p>Please ensure your ID is clear, valid, and matches your profile information.</p>
                            <?php if ($verification_status === 'rejected'): ?>
                                <p>Make sure to address the rejection reason in your new submission.</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Services Section -->
            <?php if ($is_verified): ?>
            <div id="services" class="services-section">
                <div class="profile-header">
                    <h2>My Services</h2>
                    <a href="add_service.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Service
                    </a>
                </div>
                
                <?php if ($services_result && $services_result->num_rows > 0): ?>
                    <div class="services-grid">
                        <?php while ($service = $services_result->fetch_assoc()): 
                            $service_image = $service['primary_image'] ?? $service['image'] ?? 'https://via.placeholder.com/300x200?text=Service+Image';
                        ?>
                            <div class="service-card">
                                <img src="<?= htmlspecialchars($service_image) ?>" 
                                     alt="<?= htmlspecialchars($service['name']) ?>" 
                                     class="service-card-img">
                                <h4><?= htmlspecialchars($service['name']) ?></h4>
                                <p><?= htmlspecialchars(substr($service['description'], 0, 100)) . (strlen($service['description']) > 100 ? '...' : '') ?></p>
                                <div class="service-price">₹<?= number_format($service['base_price'], 2) ?></div>
                                <div style="margin-top: 10px;">
                                    <a href="edit_my_services.php?id=<?= $service['id'] ?>" class="btn btn-outline" style="padding: 5px 10px; font-size: 12px;">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <p>You haven't added any services yet. Get started by adding your first service!</p>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="verification-note" style="margin-bottom: 30px;">
                <h3><i class="fas fa-lock"></i> Services Management</h3>
                <p>
                    <?php if ($verification_status === 'pending'): ?>
                        Your account is currently under review. You'll be able to add and manage services once your verification is complete.
                    <?php elseif ($verification_status === 'rejected'): ?>
                        Your account verification was rejected. Please upload a valid ID proof to unlock services management.
                    <?php else: ?>
                        Please complete your account verification to access services management features.
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>