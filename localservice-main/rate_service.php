<?php
session_start();

// Redirect if not logged in or not a customer
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'customer') {
    header("Location: login.php");
    exit;
}

include 'db.php';

$customer_id = $_SESSION['user']['id'];
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;

// Check if this booking is eligible for review
$booking = $conn->query("
    SELECT b.*, s.name as service_name, p.name as provider_name
    FROM bookings b
    JOIN services s ON b.service_id = s.id
    JOIN users p ON b.provider_id = p.id
    WHERE b.id = $booking_id 
    AND b.user_id = $customer_id
    AND b.status = 'completed'
")->fetch_assoc();

if (!$booking) {
    $_SESSION['error'] = "Booking not found or not eligible for review";
    header("Location: my_bookings.php");
    exit;
}

// Check if already reviewed
$existing_review = $conn->query("
    SELECT * FROM reviews 
    WHERE booking_id = $booking_id
")->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Invalid request";
        header("Location: rate_service.php?booking_id=$booking_id");
        exit;
    }

    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $comment = isset($_POST['comment']) ? $conn->real_escape_string($_POST['comment']) : '';

    // Validate rating
    if ($rating < 1 || $rating > 5) {
        $_SESSION['error'] = "Please select a rating between 1 and 5 stars";
        header("Location: rate_service.php?booking_id=$booking_id");
        exit;
    }

    if ($existing_review) {
        // Update existing review
        $stmt = $conn->prepare("UPDATE reviews SET rating = ?, comment = ? WHERE id = ?");
        $stmt->bind_param("isi", $rating, $comment, $existing_review['id']);
    } else {
        // Create new review
        $stmt = $conn->prepare("
            INSERT INTO reviews (booking_id, service_id, provider_id, user_id, rating, comment)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "iiiisi", 
            $booking_id, 
            $booking['service_id'], 
            $booking['provider_id'], 
            $customer_id, 
            $rating, 
            $comment
        );
    }

    // Execute the query and set message
    if ($stmt->execute()) {
        $_SESSION['message'] = $existing_review ? "Review updated successfully!" : "Review submitted successfully!";
    } else {
        $_SESSION['error'] = "Something went wrong while saving your review.";
    }

    $stmt->close();
    header("Location: rate_service.php?booking_id=$booking_id");
    exit;
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Display any messages or errors
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Rate Service | UrbanServe</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
            color: var(--text);
        }

        .container {
            max-width: 600px;
            margin: 30px auto;
            padding: 20px;
            background-color: var(--white);
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .header {
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 15px;
        }

        .header h1 {
            font-size: 24px;
            color: var(--secondary);
            margin-bottom: 10px;
        }

        .booking-info {
            margin-bottom: 25px;
        }

        .booking-info p { margin-bottom: 8px; }
        .booking-info strong { color: var(--secondary); }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .alert-success { background-color: #d4edda; color: #155724; }
        .alert-error { background-color: #f8d7da; color: #721c24; }

        .rating-container { margin: 25px 0; text-align: center; }
        .rating-stars { display: flex; justify-content: center; margin-bottom: 15px; }
        .rating-stars input { display: none; }
        .rating-stars label {
            font-size: 30px;
            color: var(--border);
            cursor: pointer;
            padding: 0 5px;
        }
        .rating-stars input:checked ~ label,
        .rating-stars input:checked + label {
            color: var(--warning);
        }
        .rating-stars label:hover,
        .rating-stars label:hover ~ label {
            color: var(--warning);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 4px;
            min-height: 120px;
            resize: vertical;
        }

        .btn {
            padding: 12px 20px;
            border-radius: 5px;
            font-size: 16px;
            transition: all 0.3s;
            cursor: pointer;
            display: inline-block;
            border: none;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
            width: 100%;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
            text-align: center;
            margin-top: 10px;
            display: block;
        }

        .btn-outline:hover {
            background-color: rgba(247, 109, 43, 0.1);
        }

        @media (max-width: 768px) {
            .container {
                margin: 20px;
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Rate Your Service</h1>
            <p>Please share your experience with <?= htmlspecialchars($booking['provider_name']) ?></p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="booking-info">
            <p><strong>Service:</strong> <?= htmlspecialchars($booking['service_name']) ?></p>
            <p><strong>Booking Date:</strong> <?= date('M d, Y', strtotime($booking['booking_date'])) ?></p>
            <p><strong>Provider:</strong> <?= htmlspecialchars($booking['provider_name']) ?></p>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <div class="rating-container">
                <h3>How would you rate this service?</h3>
                <div class="rating-stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <input type="radio" id="star<?= $i ?>" name="rating" value="<?= $i ?>" <?= $existing_review && $existing_review['rating'] == $i ? 'checked' : '' ?>>
                        <label for="star<?= $i ?>"><i class="fas fa-star"></i></label>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="form-group">
                <label for="comment">Share your experience (optional):</label>
                <textarea id="comment" name="comment" placeholder="What did you like about the service? How could it be improved?"><?= $existing_review ? htmlspecialchars($existing_review['comment']) : '' ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary"><?= $existing_review ? 'Update Review' : 'Submit Review' ?></button>
            <a href="customer_bookings.php" class="btn btn-outline">Cancel</a>
        </form>
    </div>

    <script>
        // Highlight stars on hover
        document.querySelectorAll('.rating-stars label').forEach(star => {
            star.addEventListener('mouseover', function () {
                const rating = this.getAttribute('for').replace('star', '');
                highlightStars(rating);
            });

            star.addEventListener('mouseout', function () {
                const checked = document.querySelector('.rating-stars input:checked');
                highlightStars(checked ? checked.value : 0);
            });
        });

        function highlightStars(rating) {
            const stars = document.querySelectorAll('.rating-stars label');
            stars.forEach((star, index) => {
                star.style.color = index < rating ? 'var(--warning)' : 'var(--border)';
            });
        }

        // Init highlight
        const checkedStar = document.querySelector('.rating-stars input:checked');
        if (checkedStar) highlightStars(checkedStar.value);
    </script>
</body>
</html>
