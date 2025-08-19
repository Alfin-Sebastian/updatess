<?php
session_start();
require_once 'db.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user']['id'];
$error = '';
$success = '';
$favorites = [];
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all'; // all, services, providers

// Handle favorite removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_favorite'])) {
    $favorite_id = filter_input(INPUT_POST, 'favorite_id', FILTER_VALIDATE_INT);
    
    if ($favorite_id) {
        $stmt = $conn->prepare("DELETE FROM favourites WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $favorite_id, $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Removed from favorites";
            header("Location: favourites.php?filter=" . $filter);
            exit;
        } else {
            $_SESSION['error'] = "Failed to remove favorite";
            header("Location: favourites.php?filter=" . $filter);
            exit;
        }
    }
}

// Get all favorites with proper image handling
try {
    $query = "
        SELECT 
            f.id as favorite_id,
            f.created_at as favorited_at,
            u.id as provider_id,
            u.name as provider_name,
            u.profile_image,
            p.avg_rating,
            p.experience,
            s.id as service_id,
            s.name as service_name,
            s.description,
            s.base_price,
            s.duration_minutes,
            (SELECT image_url FROM service_images WHERE service_id = s.id LIMIT 1) as service_image,
            sc.name as category_name
        FROM favourites f
        LEFT JOIN users u ON f.provider_id = u.id
        LEFT JOIN providers p ON u.id = p.user_id
        LEFT JOIN services s ON f.service_id = s.id
        LEFT JOIN service_categories sc ON s.category_id = sc.id
        WHERE f.user_id = ?
        ORDER BY f.created_at DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $all_favorites = $result->fetch_all(MYSQLI_ASSOC);
    
    // Filter favorites
    $favorites = array_filter($all_favorites, function($favorite) use ($filter) {
        if ($filter === 'all') return true;
        if ($filter === 'services' && $favorite['service_id']) return true;
        if ($filter === 'providers' && $favorite['provider_id']) return true;
        return false;
    });
    
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $error = "Could not load favorites";
}

// Get messages from session
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Favorites | UrbanServe</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
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
            --danger: #e53e3e;
            --danger-dark: #c53030;
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }
        
        .favorites-container {
           max-width: 1200px;
            margin: 0 auto;
        }
        
        .favorites-header {
           color: #2d3748;
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f76d2b;
 
        }
        
        .filter-container {
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
        }
        
        .filter-btn {
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            background: var(--white);
            border: 1px solid var(--border);
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            color: var(--text);
            font-weight: 500;
        }
        
        .filter-btn:hover {
            background: var(--accent);
        }
        
        .filter-btn.active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }
        
        .favorites-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .favorite-card {
            background: var(--white);
            border-radius: 0.5rem;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.2s ease;
        }
        
        .favorite-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .favorite-card-image {
            height: 180px;
            background-size: cover;
            background-position: center;
            background-color: var(--accent);
        }
        
        .favorite-card-header {
            position: relative;
            padding: 1rem;
            border-bottom: 1px solid var(--border);
        }
        
        .favorite-remove {
            position: absolute;
            top: 1rem;
            right: 1rem;
            color: var(--danger);
            cursor: pointer;
            background: none;
            border: none;
            font-size: 1.25rem;
        }
        
        .favorite-remove:hover {
            color: var(--danger-dark);
        }
        
        .favorite-content {
            padding: 1rem;
        }
        
        .favorite-type {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            background: var(--accent);
            border-radius: 0.25rem;
            font-size: 0.75rem;
            color: var(--light-text);
            margin-bottom: 0.5rem;
        }
        
        .favorite-title {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text);
        }
        
        .favorite-meta {
            display: flex;
            align-items: center;
            color: var(--light-text);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }
        
        .favorite-rating {
            color: #f59e0b;
            margin-right: 0.5rem;
        }
        
        .favorite-price {
            font-weight: 600;
            color: var(--success);
        }
        
        .favorite-date {
            font-size: 0.75rem;
            color: #a0aec0;
            margin-top: 0.5rem;
        }
        
       
        
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 0.375rem;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .favorite-empty {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--light-text);
            grid-column: 1 / -1;
        }
        
        .favorite-empty-icon {
            font-size: 3rem;
            color: #cbd5e0;
            margin-bottom: 1rem;
        }
        
        @media (max-width: 768px) {
            .favorites-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-container {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
    <div class="container">
        <div class="favorites-container">
            
                <h2 class="favorites-header">My Favorites</h2>
              
          

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="filter-container">
                <a href="favourites.php?filter=all" class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>">
                    All Favorites
                </a>
                <a href="favourites.php?filter=providers" class="filter-btn <?= $filter === 'providers' ? 'active' : '' ?>">
                    Providers
                </a>
                <a href="favourites.php?filter=services" class="filter-btn <?= $filter === 'services' ? 'active' : '' ?>">
                    Services
                </a>
            </div>

            <div class="favorites-grid">
                <?php if (!empty($favorites)): ?>
                    <?php foreach ($favorites as $favorite): ?>
                        <div class="favorite-card">
                            <?php if ($favorite['provider_id']): ?>
                                <!-- Provider Card -->
                                <div class="favorite-card-image" style="background-image: url('<?= htmlspecialchars($favorite['profile_image'] ?? 'https://ui-avatars.com/api/?name='.urlencode($favorite['provider_name']).'&background=f76d2b&color=fff') ?>')"></div>
                                <div class="favorite-card-header">
                                    <form method="POST">
                                        <input type="hidden" name="favorite_id" value="<?= $favorite['favorite_id'] ?>">
                                        <button type="submit" name="remove_favorite" class="favorite-remove" title="Remove from favorites">
                                            <i class="fas fa-heart"></i>
                                        </button>
                                    </form>
                                    <h3 class="favorite-title"><?= htmlspecialchars($favorite['provider_name']) ?></h3>
                                    <div class="favorite-meta">
                                        <?php if ($favorite['avg_rating']): ?>
                                            <span class="favorite-rating">
                                                <i class="fas fa-star"></i> <?= number_format($favorite['avg_rating'], 1) ?>
                                            </span>
                                        <?php endif; ?>
                                        <span><?= htmlspecialchars($favorite['experience']) ?></span>
                                    </div>
                                </div>
                                <div class="favorite-content">
                                    <a href="provider_profile.php?id=<?= $favorite['provider_id'] ?>" class="btn btn-primary" style="width: 90%;">
                                        <i class="fas fa-user-tie"></i> View Provider
                                    </a>
                                    <div class="favorite-date">
                                        <i class="far fa-clock"></i> Favorited on <?= date('M j, Y', strtotime($favorite['favorited_at'])) ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- Service Card -->
                                <div class="favorite-card-image" style="background-image: url('<?= htmlspecialchars($favorite['service_image'] ?? 'https://source.unsplash.com/random/300x180?service') ?>')"></div>
                                <div class="favorite-card-header">
                                    <form method="POST">
                                        <input type="hidden" name="favorite_id" value="<?= $favorite['favorite_id'] ?>">
                                        <button type="submit" name="remove_favorite" class="favorite-remove" title="Remove from favorites">
                                            <i class="fas fa-heart"></i>
                                        </button>
                                    </form>
                                    <span class="favorite-type"><?= htmlspecialchars($favorite['category_name'] ?? 'Service') ?></span>
                                    <h3 class="favorite-title"><?= htmlspecialchars($favorite['service_name']) ?></h3>
                                    <div class="favorite-meta">
                                        <span class="favorite-price">₹<?= number_format($favorite['base_price'], 2) ?></span>
                                        <span> • <?= $favorite['duration_minutes'] ?> mins</span>
                                    </div>
                                </div>
                                <div class="favorite-content">
                                    <a href="service_detail.php?id=<?= $favorite['service_id'] ?>" class="btn btn-primary" style="width: 90%;">
                                        <i class="fas fa-concierge-bell"></i> View Service
                                    </a>
                                    <div class="favorite-date">
                                        <i class="far fa-clock"></i> Favorited on <?= date('M j, Y', strtotime($favorite['favorited_at'])) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="favorite-empty">
                        <div class="favorite-empty-icon">
                            <i class="fas fa-heart-broken"></i>
                        </div>
                        <h3>No favorites found</h3>
                        <p>You haven't added any <?= $filter === 'all' ? '' : $filter ?> to your favorites yet.</p>
                        <div style="margin-top: 1.5rem; display: flex; gap: 1rem; justify-content: center;">
                            <a href="services.php" class="btn btn-primary">
                                <i class="fas fa-concierge-bell"></i> Browse Services
                            </a>
                            <a href="providers.php" class="btn btn-outline">
                                <i class="fas fa-user-tie"></i> Browse Providers
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php include 'footer.php'; ?>
</body>
</html>