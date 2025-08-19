<?php
include 'db.php';
session_start();

// Get user's favorite providers if logged in
$favorite_providers = [];
if (isset($_SESSION['user'])) {
    $user_id = $_SESSION['user']['id'];
    $stmt = $conn->prepare("SELECT provider_id FROM favourites WHERE user_id = ? AND provider_id IS NOT NULL");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $favorite_providers[] = $row['provider_id'];
    }
    $stmt->close();
}

// Get filter inputs
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_id = isset($_GET['category']) ? intval($_GET['category']) : 0;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 12;

// Fetch service categories
$categories = [];
$cat_query = $conn->query("SELECT id, name FROM service_categories");
if ($cat_query) {
    $categories = $cat_query->fetch_all(MYSQLI_ASSOC);
}

// Base query for counting total results
$count_sql = "
    SELECT COUNT(DISTINCT u.id) AS total
    FROM users u
    JOIN providers p ON u.id = p.user_id
    WHERE u.role = 'provider' AND p.is_verified = 1
";

// Base query for fetching providers
$sql = "
    SELECT 
        u.id, 
        u.name, 
        u.profile_image, 
        u.city, 
        u.state, 
        p.experience, 
        p.bio, 
        p.avg_rating,
        (
            SELECT GROUP_CONCAT(DISTINCT s.name SEPARATOR ', ')
            FROM provider_services ps
            JOIN services s ON ps.service_id = s.id
            WHERE ps.provider_id = u.id
        ) AS services_offered,
        (
            SELECT GROUP_CONCAT(DISTINCT sc.name SEPARATOR ', ')
            FROM provider_services ps
            JOIN services s ON ps.service_id = s.id
            JOIN service_categories sc ON s.category_id = sc.id
            WHERE ps.provider_id = u.id
        ) AS categories_offered
    FROM users u
    JOIN providers p ON u.id = p.user_id
    WHERE u.role = 'provider' AND p.is_verified = 1
";

// Build WHERE conditions
$conditions = [];
if (!empty($search)) {
    $safe_search = $conn->real_escape_string($search);
    $conditions[] = "(u.name LIKE '%$safe_search%' OR 
                     u.city LIKE '%$safe_search%' OR
                     u.state LIKE '%$safe_search%' OR
                     p.experience LIKE '%$safe_search%' OR
                     p.bio LIKE '%$safe_search%')";
}

if ($category_id > 0) {
    $conditions[] = "u.id IN (
        SELECT ps.provider_id 
        FROM provider_services ps
        JOIN services s ON ps.service_id = s.id
        WHERE s.category_id = $category_id
    )";
}

// Append conditions to SQL
if (!empty($conditions)) {
    $where_clause = " AND " . implode(" AND ", $conditions);
    $count_sql .= $where_clause;
    $sql .= $where_clause;
}

// Get total providers and calculate pagination
$total_result = $conn->query($count_sql)->fetch_assoc();
$total_providers = $total_result['total'];
$total_pages = ceil($total_providers / $per_page);
$offset = ($page - 1) * $per_page;
$sql .= " ORDER BY p.avg_rating DESC, u.name ASC LIMIT $offset, $per_page";

$query = $conn->query($sql);
$providers = $query ? $query->fetch_all(MYSQLI_ASSOC) : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Providers | UrbanServe</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        body {
            font-family: 'Arial', sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f8f9fa;
            margin: 0;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        h2 {
            color: #2d3748;
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f76d2b;
        }
        
        .search-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .search-form {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .search-input {
            flex: 1;
            min-width: 250px;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        
        .category-select {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            background: white;
        }
        
        .search-button {
            padding: 10px 20px;
            background-color: #f76d2b;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .search-button:hover {
            background-color: #e05b1a;
        }
        
        .reset-button {
            padding: 10px 20px;
            background-color: #e2e8f0;
            color: #4a5568;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .reset-button:hover {
            background-color: #cbd5e0;
        }
        
        .providers-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .provider-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
        }
        
        .provider-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .provider-image {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 50%;
            margin: 0 auto 15px;
            display: block;
            border: 3px solid #f76d2b;
        }
        
        .provider-name {
            color: #f76d2b;
            font-size: 1.2rem;
            margin-bottom: 8px;
            text-align: center;
        }
        
        .provider-rating {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            margin-bottom: 10px;
            color: #f8c537;
        }
        
        .provider-services {
            display: inline-block;
            background: #e2e8f0;
            color: #4a5568;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            margin-bottom: 10px;
        }
        
        .provider-categories {
            font-size: 0.8rem;
            color: #718096;
            margin-bottom: 10px;
        }
        
        .provider-description {
            color: #4a5568;
            font-size: 0.95rem;
            margin-bottom: 15px;
        }
        
        .provider-location {
            color: #718096;
            font-size: 0.9rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        
        .action-links {
            border-top: 1px solid #e2e8f0;
            padding-top: 15px;
            display: flex;
            gap: 10px;
        }
        
        .action-links a {
            flex: 1;
            text-align: center;
            padding: 8px 15px;
            border-radius: 4px;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .action-links a:first-child {
            background-color: #f0f4f8;
            color: #4a5568;
            border: 1px solid #e2e8f0;
        }
        
        .action-links a:first-child:hover {
            background-color: #e2e8f0;
        }
        
        .action-links a:last-child {
            background-color: #f76d2b;
            color: white;
            border: 1px solid #f76d2b;
        }
        
        .action-links a:last-child:hover {
            background-color: #e05b1a;
            border-color: #e05b1a;
        }
        
        .no-results {
            text-align: center;
            grid-column: 1 / -1;
            padding: 40px;
            color: #718096;
        }
        
        .back-link-container {
            text-align: center;
            margin-top: 30px;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background-color: #f76d2b;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 500;
            transition: background-color 0.3s;
        }
        
        .back-link:hover {
            background-color: #e05b1a;
        }
        
        .back-link i {
            font-size: 0.9rem;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 30px 0;
        }
        
        .pagination a, .pagination span {
            padding: 8px 16px;
            border-radius: 4px;
            text-decoration: none;
            color: #4a5568;
            border: 1px solid #e2e8f0;
        }
        
        .pagination a:hover {
            background-color: #f76d2b;
            color: white;
            border-color: #f76d2b;
        }
        
        .pagination .active {
            background-color: #f76d2b;
            color: white;
            border-color: #f76d2b;
        }
        
        .pagination .disabled {
            color: #cbd5e0;
            pointer-events: none;
        }

        /* Favorite Button Styles */
        .favorite-btn {
            background: none;
            border: none;
            color: #ccc;
            cursor: pointer;
            font-size: 18px;
            padding: 8px;
            transition: all 0.2s;
            outline: none;
            position: absolute;
            top: 15px;
            right: 15px;
            z-index: 1;
        }
        
        .favorite-btn:hover {
            color: #f76d2b;
        }
        
        .favorite-btn.active {
            color: #f76d2b;
        }

        @media (max-width: 768px) {
            .search-form {
                flex-direction: column;
            }
            
            .providers-list {
                grid-template-columns: 1fr;
            }
            
            .action-links {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
    <div class="container">
        <h2>Browse Service Providers</h2>
        
        <!-- Search Form -->
        <div class="search-container">
            <form method="GET" class="search-form">
                <input type="text" name="search" class="search-input" 
                       placeholder="Search providers..." value="<?= htmlspecialchars($search) ?>">
                
                <select name="category" class="category-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= ($category_id == $cat['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <button type="submit" class="search-button">Search</button>
                <a href="providers.php" class="reset-button">Reset</a>
            </form>
        </div>
        
        <!-- Providers List -->
        <div class="providers-list">
            <?php if (count($providers) > 0): ?>
                <?php foreach ($providers as $provider): 
                    $is_favorite = in_array($provider['id'], $favorite_providers);
                ?>
                    <div class="provider-card">
                        <?php if (isset($_SESSION['user'])): ?>
                        <form method="POST" action="favorite.php" style="display: inline;">
                            <input type="hidden" name="provider_id" value="<?= $provider['id'] ?>">
                            <button type="submit" class="favorite-btn <?= $is_favorite ? 'active' : '' ?>">
                                <i class="fas fa-heart"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                        
                        <img src="<?= htmlspecialchars($provider['profile_image'] ?: 'default-avatar.jpg') ?>" 
                             alt="<?= htmlspecialchars($provider['name']) ?>" 
                             class="provider-image"
                             onerror="this.src='default-avatar.jpg'">
                        
                        <h3 class="provider-name"><?= htmlspecialchars($provider['name']) ?></h3>
                        
                        <div class="provider-rating">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star<?= $i <= round($provider['avg_rating']) ? '' : '-half-alt' ?>"></i>
                            <?php endfor; ?>
                            <span>(<?= number_format($provider['avg_rating'], 1) ?>)</span>
                        </div>
                        
                        <?php if (!empty($provider['services_offered'])): ?>
                            <div class="provider-services"><?= htmlspecialchars($provider['services_offered']) ?></div>
                        <?php endif; ?>
                        
                        <?php if (!empty($provider['categories_offered'])): ?>
                            <div class="provider-categories">
                                <small>Categories: <?= htmlspecialchars($provider['categories_offered']) ?></small>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($provider['bio'])): ?>
                            <div class="provider-description"><?= htmlspecialchars($provider['bio']) ?></div>
                        <?php endif; ?>
                        
                        <div class="provider-location">
                            <i class="fas fa-map-marker-alt"></i>
                            <?= htmlspecialchars($provider['city'] . ', ' . $provider['state']) ?>
                        </div>
                        
                        <div class="action-links">
                            <a href="provider_profile.php?id=<?= $provider['id'] ?>">View Profile</a>
                            <a href="book_service.php?provider_id=<?= $provider['id'] ?>" class="book-now-btn">Book Now</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-results">
                    <p>No providers found matching your search criteria.</p>
                    <p><a href="providers.php">Show all providers</a></p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                        <i class="fas fa-chevron-left"></i> Prev
                    </a>
                <?php else: ?>
                    <span class="disabled"><i class="fas fa-chevron-left"></i> Prev</span>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <span class="disabled">Next <i class="fas fa-chevron-right"></i></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- Back Link -->
        <div class="back-link-container">
            <a href="index.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Home
            </a>
        </div>
    </div>
<?php include 'footer.php'; ?>
</body>
</html>