<?php
include 'db.php';
session_start();

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'customer') {
    header("Location: login.php");
    exit;
}

$error = '';
$success = '';
$services = [];
$filtered_providers = [];
$busy_slots = [];
$from_detail = isset($_GET['from_detail']);

// Get all services
$service_query = $conn->query("
    SELECT s.id, s.name, s.category_id, sc.name as category_name, s.duration_minutes 
    FROM services s 
    JOIN service_categories sc ON s.category_id = sc.id
    ORDER BY s.name
");
$services = $service_query->fetch_all(MYSQLI_ASSOC);

// Check if coming with a specific service pre-selected
$service_id = $_GET['service_id'] ?? ($_POST['service_id'] ?? null);
$preselected_service = null;

if ($service_id) {
    // Validate the service exists
    $service_stmt = $conn->prepare("SELECT id, name FROM services WHERE id = ?");
    $service_stmt->bind_param("i", $service_id);
    $service_stmt->execute();
    $preselected_service = $service_stmt->get_result()->fetch_assoc();
    
    if ($preselected_service) {
        // Get providers offering this service
        $provider_query = $conn->prepare("
            SELECT u.id, u.name, ps.price, s.duration_minutes as duration
            FROM users u
            JOIN provider_services ps ON ps.provider_id = u.id
            JOIN services s ON ps.service_id = s.id
            WHERE u.role = 'provider' AND ps.service_id = ?
        ");
        $provider_query->bind_param("i", $service_id);
        $provider_query->execute();
        $filtered_providers = $provider_query->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Get busy slots for these providers
        if (!empty($filtered_providers)) {
            $provider_ids = array_column($filtered_providers, 'id');
            $placeholders = implode(',', array_fill(0, count($provider_ids), '?'));
            
            $busy_query = $conn->prepare("
                SELECT b.provider_id, b.booking_date as start_time, 
                       DATE_ADD(b.booking_date, INTERVAL s.duration_minutes MINUTE) as end_time
                FROM bookings b
                JOIN services s ON b.service_id = s.id
                WHERE b.provider_id IN ($placeholders)
                AND b.status NOT IN ('cancelled', 'rejected', 'completed')
                AND b.booking_date > NOW()
                ORDER BY start_time
            ");
            $busy_query->bind_param(str_repeat('i', count($provider_ids)), ...$provider_ids);
            $busy_query->execute();
            $result = $busy_query->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $busy_slots[$row['provider_id']][] = [
                    'start' => $row['start_time'],
                    'end' => $row['end_time']
                ];
            }
        }
    } else {
        $service_id = null;
    }
}

// Handle booking submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $provider_id = intval($_POST['provider_id'] ?? 0);
    $service_id = intval($_POST['service_id'] ?? 0);
    $date = trim($_POST['date'] ?? '');
    $customer_id = $_SESSION['user']['id'];
    $address = trim($_SESSION['user']['address'] ?? '');

    // Validate required fields
    if (empty($provider_id) || empty($service_id) || empty($date)) {
        $error = "Please fill all required fields";
    } elseif (strtotime($date) === false) {
        $error = "Invalid date/time format";
    } elseif (strtotime($date) < time()) {
        $error = "Booking date must be in the future";
    } elseif (empty($address)) {
        $error = "Please set your address in your profile before booking";
    } else {
        // Get service duration and price
        $check = $conn->prepare("
            SELECT s.duration_minutes as duration, ps.price
            FROM provider_services ps
            JOIN services s ON ps.service_id = s.id
            WHERE ps.provider_id = ? AND ps.service_id = ?
        ");
        $check->bind_param("ii", $provider_id, $service_id);
        $check->execute();
        $result = $check->get_result();
        
        if ($result->num_rows === 0) {
            $error = "Selected provider doesn't offer this service";
        } else {
            $service_data = $result->fetch_assoc();
            $duration = $service_data['duration'];
            $price = $service_data['price'];
            
            // Check for time slot availability
            $start_time = new DateTime($date);
            $end_time = clone $start_time;
            $end_time->add(new DateInterval('PT'.$duration.'M'));
            
            $overlap = false;
            if (isset($busy_slots[$provider_id])) {
                foreach ($busy_slots[$provider_id] as $slot) {
                    $busy_start = new DateTime($slot['start']);
                    $busy_end = new DateTime($slot['end']);
                    
                    if (!($end_time <= $busy_start || $start_time >= $busy_end)) {
                        $overlap = true;
                        break;
                    }
                }
            }
            
            if ($overlap) {
                $error = "This time slot is already booked. Please choose another time.";
            } else {
                // Create booking
                $stmt = $conn->prepare("
                    INSERT INTO bookings 
                    (user_id, provider_id, service_id, booking_date, address, amount, status, payment_type) 
                    VALUES (?, ?, ?, ?, ?, ?, 'pending', 'cash')
                ");
                $stmt->bind_param("iiissd", 
                    $customer_id, 
                    $provider_id, 
                    $service_id, 
                    $date, 
                    $address, 
                    $price
                );
                
                if ($stmt->execute()) {
                    $success = "Booking confirmed!";
                    $_POST = [];
                    $from_detail = false;
                } else {
                    $error = "Booking failed. Please try again.";
                }
            }
        }
    }
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Service | UrbanServe</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        /* Your existing CSS styles */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 0;
            color: #333;
        }
        .container {
            max-width: 800px;
            margin: 30px auto;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h2 {
            margin-top: 0;
            color: #2c3e50;
        }
        .form-section {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }
        select, input[type="datetime-local"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        .btn {
            background-color: #f76d2b;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: background-color 0.3s;
        }
        .btn:hover {
            background-color: #f76d2b;
        }
        .alert {
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .busy-slots {
            margin: 20px 0;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 4px;
        }
        .busy-slot {
            padding: 8px;
            margin: 5px 0;
            background-color: #e9ecef;
            border-radius: 3px;
        }
        .provider-option {
            display: flex;
            justify-content: space-between;
        }
        .provider-price {
            color: #6c757d;
        }
        .service-info {
            margin-top: 5px;
            font-size: 14px;
            color: #6c757d;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #f76d2b;
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .preselected-service {
            margin-bottom: 20px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Book a Service</h2>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php elseif ($error): ?>
            <div class="alert alert-error">
                <?= htmlspecialchars($error) ?>
                <?php if (strpos($error, 'address') !== false): ?>
                    <br><a href="edit_profile.php">Update your address</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" id="bookingForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            
            <?php if ($preselected_service && $from_detail): ?>
                <div class="preselected-service">
                    <strong>Selected Service:</strong>
                    <p><?= htmlspecialchars($preselected_service['name']) ?></p>
                </div>
                <input type="hidden" name="service_id" value="<?= $preselected_service['id'] ?>">
            <?php else: ?>
                <div class="form-section">
                    <label for="service_id">Select Service</label>
                    <select name="service_id" id="service_id" required 
                            onchange="window.location.href='book_service.php?service_id='+this.value">
                        <option value="">-- Choose a service --</option>
                        <?php foreach ($services as $s): ?>
                            <option value="<?= $s['id'] ?>" 
                                <?= ($service_id == $s['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['category_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($service_id): ?>
                        <?php 
                            $selected_service = current(array_filter($services, fn($s) => $s['id'] == $service_id));
                        ?>
                        <div class="service-info">
                            Duration: <?= $selected_service['duration_minutes'] ?> minutes
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($service_id && !empty($filtered_providers)): ?>
                <div class="form-section">
                    <label for="provider_id">Select Provider</label>
                    <select name="provider_id" id="provider_id" required>
                        <option value="">-- Choose a provider --</option>
                        <?php foreach ($filtered_providers as $p): ?>
                            <option value="<?= $p['id'] ?>" 
                                <?= (isset($_POST['provider_id']) && $_POST['provider_id'] == $p['id']) ? 'selected' : '' ?>>
                                <span class="provider-option">
                                    <span><?= htmlspecialchars($p['name']) ?></span>
                                    <span class="provider-price">₹<?= $p['price'] ?> (<?= $p['duration'] ?> mins)</span>
                                </span>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if (isset($_POST['provider_id'])): ?>
                    <div class="busy-slots">
                        <h3>Provider's Booked Time Slots</h3>
                        <?php if (!empty($busy_slots[$_POST['provider_id']])): ?>
                            <?php foreach ($busy_slots[$_POST['provider_id']] as $slot): ?>
                                <?php
                                    $start = new DateTime($slot['start']);
                                    $end = new DateTime($slot['end']);
                                ?>
                                <div class="busy-slot">
                                    <?= $start->format('D, M j g:i A') ?> - <?= $end->format('g:i A') ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>No upcoming bookings for this provider</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <div class="form-section">
                    <label for="date">Preferred Date & Time</label>
                    <input type="datetime-local" id="date" name="date" required
                           min="<?= date('Y-m-d\TH:i') ?>"
                           value="<?= htmlspecialchars($_POST['date'] ?? '') ?>">
                </div>
                
                <div class="form-section">
                    <label>Service Address</label>
                    <p><?= htmlspecialchars($_SESSION['user']['address'] ?? 'Address not specified') ?></p>
                    <small><a href="edit_profile.php">Update address</a></small>
                </div>
                
                <button type="submit" class="btn">Confirm Booking</button>
            <?php elseif ($service_id && empty($filtered_providers)): ?>
                <div class="alert alert-error">
                    No providers available for this service. <a href="services.php">Browse other services</a>
                </div>
            <?php endif; ?>
        </form>
        
        <a href="<?= $from_detail ? 'service_detail.php?id='.$service_id : 'services.php' ?>" class="back-link">← Back</a>
    </div>

    <script>
        document.getElementById('bookingForm')?.addEventListener('submit', function(e) {
            const serviceId = document.getElementById('service_id')?.value;
            const providerId = document.getElementById('provider_id')?.value;
            const date = document.getElementById('date')?.value;
            
            if (!serviceId || !providerId || !date) {
                e.preventDefault();
                alert('Please fill all required fields');
                return false;
            }
            
            // Additional validation for date
            const selectedDate = new Date(date);
            if (selectedDate < new Date()) {
                e.preventDefault();
                alert('Booking date must be in the future');
                return false;
            }
        });

        document.getElementById('provider_id')?.addEventListener('change', function() {
            if (this.value) {
                document.getElementById('bookingForm').submit();
            }
        });
    </script>
</body>
</html>