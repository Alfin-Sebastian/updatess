<?php
include 'db.php';
session_start();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: providers.php");
    exit;
}

$provider_id = intval($_GET['id']);

// Fetch provider basic details
$stmt = $conn->prepare("
    SELECT u.id, u.name, u.profile_image, u.email, u.phone, u.city, u.state, 
           p.experience, p.bio, p.avg_rating, p.is_verified
    FROM users u
    JOIN providers p ON u.id = p.user_id
    WHERE u.id = ? AND u.role = 'provider'
");
$stmt->bind_param("i", $provider_id);
$stmt->execute();
$provider_result = $stmt->get_result();

if ($provider_result->num_rows === 0) {
    header("Location: providers.php?error=not_found");
    exit;
}

$provider = $provider_result->fetch_assoc();

// Fetch services offered by this provider with images
$services_stmt = $conn->prepare("
    SELECT s.id, s.name, s.description, s.duration_minutes, ps.price,
           (SELECT image_url FROM service_images WHERE service_id = s.id LIMIT 1) as service_image
    FROM provider_services ps
    JOIN services s ON ps.service_id = s.id
    WHERE ps.provider_id = ?
");
$services_stmt->bind_param("i", $provider_id);
$services_stmt->execute();
$services_result = $services_stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($provider['name']) ?> | UrbanServe Provider</title>
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
            margin: 30px auto;
            background-color: var(--white);
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .profile-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 40px 20px;
            background: linear-gradient(rgba(247, 109, 43, 0.1), rgba(247, 109, 43, 0.05));
            text-align: center;
            position: relative;
        }

        .profile-img {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid var(--white);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .verified-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background-color: var(--success);
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .profile-name {
            font-size: 28px;
            font-weight: 700;
            color: var(--secondary);
            margin: 0 0 10px;
        }

        .profile-meta {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
            margin-bottom: 20px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
            color: var(--light-text);
        }

        .rating-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background-color: rgba(247, 109, 43, 0.1);
            color: var(--primary);
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: 600;
        }

        .profile-bio {
            max-width: 700px;
            margin: 0 auto;
            color: var(--text);
            font-size: 15px;
            line-height: 1.7;
            padding: 0 30px;
        }

        .section-title {
            font-size: 22px;
            font-weight: 600;
            color: var(--secondary);
            margin: 40px 0 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--border);
            position: relative;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 80px;
            height: 2px;
            background-color: var(--primary);
        }

        .services-container {
            padding: 0 30px 30px;
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .service-card {
            background-color: var(--white);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .service-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }

        .service-image {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .service-name {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary);
            margin: 0 0 10px;
        }

        .service-description {
            color: var(--text);
            font-size: 14px;
            margin-bottom: 15px;
            line-height: 1.6;
        }

        .service-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
            font-size: 14px;
            color: var(--light-text);
        }

        .service-price {
            font-size: 20px;
            font-weight: 700;
            color: var(--secondary);
            margin: 10px 0;
        }

        .btn {
            display: inline-block;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 15px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
            border: 1px solid var(--primary);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        .empty-state {
            text-align: center;
            padding: 30px;
            color: var(--light-text);
        }

 .back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 20px;
    color: white;
    text-decoration: none;
    font-weight: 600;
    padding: 8px 16px;
    border-radius: 6px;
    background-color: var(--primary);
    transition: all 0.3s ease;
    border: 1px solid var(--primary);
}

.back-link:hover {
    background-color: #e05b1a;
    text-decoration: none;
    color: white;
}

.back-link i {
    font-size: 0.9rem;
}

        @media (max-width: 768px) {
            .profile-header {
                padding: 30px 15px;
            }
            
            .profile-img {
                width: 120px;
                height: 120px;
            }
            
            .services-container {
                padding: 0 15px 20px;
            }
            
            .services-grid {
                grid-template-columns: 1fr;
            }
            
            .service-card {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
     
        <a href="providers.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Providers
        </a>
        <div class="profile-header">
            <?php if ($provider['is_verified']): ?>
                <div class="verified-badge">
                    <i class="fas fa-check-circle"></i> Verified
                </div>
            <?php endif; ?>
            
            <img src="<?= htmlspecialchars($provider['profile_image'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($provider['name']) . '&background=f76d2b&color=fff') ?>" 
                 alt="<?= htmlspecialchars($provider['name']) ?>" class="profile-img">
            
            <h1 class="profile-name"><?= htmlspecialchars($provider['name']) ?></h1>
            
            <div class="profile-meta">
                <?php if (!empty($provider['city']) || !empty($provider['state'])): ?>
                    <div class="meta-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <?= htmlspecialchars(($provider['city'] ?? '') . (!empty($provider['state']) ? ', ' . $provider['state'] : '')) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($provider['avg_rating'] > 0): ?>
                    <div class="meta-item">
                        <i class="fas fa-star"></i>
                        <span class="rating-badge"><?= number_format($provider['avg_rating'], 1) ?> (<?= $conn->query("SELECT COUNT(*) FROM reviews WHERE provider_id = $provider_id")->fetch_row()[0] ?>)</span>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($provider['experience'])): ?>
                    <div class="meta-item">
                        <i class="fas fa-briefcase"></i>
                        <?= htmlspecialchars($provider['experience']) ?> experience
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="profile-bio">
            <h2 class="section-title">About</h2>
            <?php if (!empty($provider['bio'])): ?>
                <p><?= nl2br(htmlspecialchars($provider['bio'])) ?></p>
            <?php else: ?>
                <p>This provider hasn't added a bio yet.</p>
            <?php endif; ?>
        </div>
        
        <div class="services-container">
            <h2 class="section-title">Services Offered</h2>
            
            <?php if ($services_result->num_rows > 0): ?>
                <div class="services-grid">
                    <?php while ($service = $services_result->fetch_assoc()): 
                        $service_image = $service['service_image'] ?? 'https://via.placeholder.com/400x300?text=Service+Image';
                    ?>
                        <div class="service-card">
                            <img src="<?= htmlspecialchars($service_image) ?>" 
                                 alt="<?= htmlspecialchars($service['name']) ?>" 
                                 class="service-image">
                            
                            <h3 class="service-name"><?= htmlspecialchars($service['name']) ?></h3>
                            <p class="service-description"><?= htmlspecialchars($service['description']) ?></p>
                            
                            <div class="service-meta">
                                <?php if (!empty($service['duration_minutes'])): ?>
                                    <div class="meta-item">
                                        <i class="far fa-clock"></i>
                                        <?= (int)$service['duration_minutes'] ?> mins
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="service-price">₹<?= number_format($service['price'], 2) ?></div>
                            
                            <?php if (isset($_SESSION['user']) && $_SESSION['user']['role'] === 'customer'): ?>
                                <a href="book_service.php?provider_id=<?= $provider_id ?>&service_id=<?= $service['id'] ?>" 
                                   class="btn btn-primary">
                                    <i class="fas fa-calendar-check"></i> Book Now
                                </a>
                            <?php elseif (!isset($_SESSION['user'])): ?>
                                <a href="login.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" 
                                   class="btn btn-primary">
                                    <i class="fas fa-sign-in-alt"></i> Login to Book
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <p>This provider hasn't added any services yet.</p>
                </div>
            <?php endif; ?>
        </div>

    </div>
</body>
</html>