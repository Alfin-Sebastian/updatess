<?php
session_start();
include 'db.php';

if (!isset($_GET['id'])) {
    header("Location: services.php");
    exit;
}

$service_id = intval($_GET['id']);

// Fetch service details with provider info
$service_stmt = $conn->prepare("
    SELECT 
        s.*, 
        sc.name AS category_name,
        u.id AS provider_id,
        u.name AS provider_name,
        u.profile_image AS provider_image,
        p.experience,
        p.avg_rating,
        p.bio AS provider_bio
    FROM services s
    JOIN service_categories sc ON s.category_id = sc.id
    JOIN provider_services ps ON s.id = ps.service_id
    JOIN users u ON ps.provider_id = u.id
    JOIN providers p ON u.id = p.user_id
    WHERE s.id = ?
");
$service_stmt->bind_param("i", $service_id);
$service_stmt->execute();
$service_result = $service_stmt->get_result();

if ($service_result->num_rows === 0) {
    header("Location: services.php?error=not_found");
    exit;
}
$service = $service_result->fetch_assoc();

// Fetch service images
$images_stmt = $conn->prepare("
    SELECT image_url FROM service_images 
    WHERE service_id = ?
    ORDER BY id ASC
");
$images_stmt->bind_param("i", $service_id);
$images_stmt->execute();
$images_result = $images_stmt->get_result();
$service_images = $images_result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($service['name']) ?> | UrbanServe</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #f76d2b;
            --primary-light: rgba(247, 109, 43, 0.1);
            --secondary: #2d3748;
            --accent: #f0f4f8;
            --text: #2d3748;
            --light-text: #718096;
            --border: #e2e8f0;
            --white: #ffffff;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
            color: var(--text);
            line-height: 1.6;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .service-header {
            display: flex;
            gap: 30px;
            margin-bottom: 40px;
            flex-wrap: wrap;
        }

        .service-gallery {
            flex: 1;
            min-width: 300px;
        }

        .main-image {
            width: 100%;
            height: 400px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .thumbnail-container {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
        }

        .thumbnail {
            width: 100%;
            height: 80px;
            object-fit: cover;
            border-radius: 4px;
            cursor: pointer;
            transition: transform 0.3s;
        }

        .thumbnail:hover {
            transform: scale(1.05);
        }

        .service-info {
            flex: 1;
            min-width: 300px;
        }

        h1 {
            color: var(--secondary);
            margin-bottom: 15px;
            font-size: 2rem;
        }

        .service-meta {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            color: var(--light-text);
        }

        .service-category {
            background-color: var(--primary-light);
            color: var(--primary);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .service-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--secondary);
            margin: 20px 0;
        }

        .service-description {
            margin-bottom: 30px;
            line-height: 1.8;
        }

        .provider-section {
            margin-top: 40px;
            padding: 25px;
            background-color: var(--white);
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .provider-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }

        .provider-image {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary);
        }

        .provider-info h2 {
            color: var(--secondary);
            margin-bottom: 5px;
        }

        .provider-rating {
            color: var(--primary);
            font-weight: 600;
        }

        .provider-experience {
            color: var(--light-text);
            margin-bottom: 10px;
        }

        .provider-bio {
            line-height: 1.7;
            color: var(--text);
        }

        .booking-section {
            margin-top: 40px;
            padding: 25px;
            background-color: var(--white);
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
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
            .service-header {
                flex-direction: column;
            }
            
            .thumbnail-container {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .main-image {
                height: 300px;
            }
        }

        @media (max-width: 480px) {
            .thumbnail-container {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        .reviews-section {
        margin-top: 40px;
        padding: 25px;
        background-color: var(--white);
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }

    .reviews-section h2 {
        color: var(--secondary);
        margin-bottom: 20px;
        font-size: 1.5rem;
    }

    .review-card {
        padding: 20px;
        border-bottom: 1px solid var(--border);
        margin-bottom: 15px;
    }

    .review-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 15px;
    }

    .reviewer-image {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        object-fit: cover;
    }

    .reviewer-info h3 {
        color: var(--secondary);
        margin-bottom: 5px;
    }

    .review-rating {
        color: var(--warning);
        font-size: 0.9rem;
    }

    .review-date {
        color: var(--light-text);
        font-size: 0.8rem;
        margin-left: 10px;
    }

    .review-comment {
        margin-top: 10px;
        line-height: 1.7;
        color: var(--text);
    }

    .no-reviews {
        color: var(--light-text);
        font-style: italic;
        text-align: center;
        padding: 20px;
    }

    /* Add to :root if not already present */
    :root {
        --warning: #dd6b20;
    }
    </style>
</head>
<body>
    <div class="container">
  <a href="services.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to Services
    </a>
        <div class="service-header">
            <div class="service-gallery">
                <?php if (!empty($service_images)): ?>
                    <img id="mainImage" src="<?= htmlspecialchars($service_images[0]['image_url']) ?>" 
                         alt="<?= htmlspecialchars($service['name']) ?>" class="main-image">
                    
                    <div class="thumbnail-container">
                        <?php foreach ($service_images as $index => $image): ?>
                            <img src="<?= htmlspecialchars($image['image_url']) ?>" 
                                 alt="<?= htmlspecialchars($service['name']) ?> - Image <?= $index + 1 ?>" 
                                 class="thumbnail"
                                 onclick="document.getElementById('mainImage').src = this.src">
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <img id="mainImage" src="<?= htmlspecialchars($service['image'] ?? 'https://via.placeholder.com/600x400?text=Service+Image') ?>" 
                         alt="<?= htmlspecialchars($service['name']) ?>" class="main-image">
                <?php endif; ?>
            </div>
            
            <div class="service-info">
                <h1><?= htmlspecialchars($service['name']) ?></h1>
                <div class="service-meta">
                    <span class="service-category"><?= htmlspecialchars($service['category_name']) ?></span>
                    <span><i class="far fa-clock"></i> <?= htmlspecialchars($service['duration_minutes']) ?> mins</span>
                </div>
                <div class="service-price">₹<?= number_format($service['base_price'], 2) ?></div>
                <div class="service-description">
                    <?= nl2br(htmlspecialchars($service['description'])) ?>
                </div>
                
                <div class="provider-section">
                    <div class="provider-header">
                        <img src="<?= htmlspecialchars($service['provider_image'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($service['provider_name']) . '&background=f76d2b&color=fff') ?>" 
                             alt="<?= htmlspecialchars($service['provider_name']) ?>" class="provider-image">
                        <div class="provider-info">
                            <h2><?= htmlspecialchars($service['provider_name']) ?></h2>
                            <?php if ($service['avg_rating'] > 0): ?>
                                <div class="provider-rating">★ <?= number_format($service['avg_rating'], 1) ?> Rating</div>
                            <?php endif; ?>
                            <div class="provider-experience">
                                <i class="fas fa-briefcase"></i> <?= htmlspecialchars($service['experience']) ?> experience
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($service['provider_bio'])): ?>
                        <div class="provider-bio">
                            <?= nl2br(htmlspecialchars($service['provider_bio'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
         
    

        <div class="booking-section">
            <?php if (isset($_SESSION['user'])): ?>
                <?php if ($_SESSION['user']['role'] === 'customer'): ?>
                    <h2>Book This Service</h2>
                    <form method="GET" action="book_service.php" class="booking-form">
                        <input type="hidden" name="service_id" value="<?= $service_id ?>">
                        <input type="hidden" name="from_detail" value="1">
                        <button type="submit" class="back-link">Book Now</button>
                    </form>
                <?php elseif ($_SESSION['user']['role'] === 'provider' && $_SESSION['user']['id'] == $service['provider_id']): ?>
                    <a href="edit_my_services.php?id=<?= $service_id ?>" class="back-link">Edit Service</a>
                <?php elseif ($_SESSION['user']['role'] === 'admin'): ?>
                    <a href="edit_services.php?id=<?= $service_id ?>" class="back-link">Edit Service</a>
                <?php endif; ?>
            <?php else: ?>
                <h2>Want to book this service?</h2>
                <a href="login.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="back-link">
                    <i class="fas fa-sign-in-alt"></i> Login to Book
                </a>
            <?php endif; ?>
        </div>

            <div class="reviews-section">
    <h2>Customer Reviews</h2>
    
    <?php
    // Fetch reviews for this service
    $reviews_stmt = $conn->prepare("
        SELECT r.*, u.name AS customer_name, u.profile_image AS customer_image
        FROM reviews r
        JOIN users u ON r.user_id = u.id
        WHERE r.service_id = ?
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $reviews_stmt->bind_param("i", $service_id);
    $reviews_stmt->execute();
    $reviews_result = $reviews_stmt->get_result();
    $reviews = $reviews_result->fetch_all(MYSQLI_ASSOC);
    ?>
    
    <?php if (count($reviews) > 0): ?>
        <div class="reviews-list">
            <?php foreach ($reviews as $review): ?>
                <div class="review-card">
                    <div class="review-header">
                        <img src="<?= htmlspecialchars($review['customer_image'] ?? ('https://ui-avatars.com/api/?name=' . urlencode($review['customer_name']))) ?>" 
                             alt="<?= htmlspecialchars($review['customer_name']) ?>" 
                             class="reviewer-image">
                        <div class="reviewer-info">
                            <h3><?= htmlspecialchars($review['customer_name']) ?></h3>
                            <div class="review-rating">
                                <?= str_repeat('★', $review['rating']) . str_repeat('☆', 5 - $review['rating']) ?>
                                <span class="review-date"><?= date('M d, Y', strtotime($review['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>
                    <?php if (!empty($review['comment'])): ?>
                        <div class="review-comment">
                            <?= nl2br(htmlspecialchars($review['comment'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="no-reviews">No reviews yet for this service.</p>
    <?php endif; ?>
        </div>
    </div>

    <script>
        // Simple image gallery functionality
        const thumbnails = document.querySelectorAll('.thumbnail');
        const mainImage = document.getElementById('mainImage');
        
        thumbnails.forEach(thumb => {
            thumb.addEventListener('click', function() {
                // Highlight selected thumbnail
                thumbnails.forEach(t => t.style.border = 'none');
                this.style.border = '2px solid var(--primary)';
            });
        });
    </script>
</body>
</html>