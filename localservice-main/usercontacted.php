<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

include 'db.php';

$user_id = $_SESSION['user']['id'];

// Get all contact messages for this user
$query = "SELECT cm.*, 
                 (SELECT COUNT(*) FROM message_replies mr WHERE mr.message_id = cm.id) as reply_count
          FROM contact_messages cm
          WHERE cm.user_id = ?
          ORDER BY cm.created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Function to get replies for a message
function getMessageReplies($conn, $message_id) {
    $query = "SELECT mr.*, u.name as admin_name 
              FROM message_replies mr
              JOIN users u ON mr.admin_id = u.id
              WHERE mr.message_id = ?
              ORDER BY mr.created_at ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $message_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Messages | UrbanServe</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
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
        }

        body {
            font-family: 'Arial', sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 0;
        }

        .user-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .user-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--primary);
        }

        .user-title {
            color: var(--secondary);
            font-size: 24px;
        }

        .back-btn {
            padding: 8px 16px;
            background-color: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            transition: background-color 0.3s;
        }

        .back-btn:hover {
            background-color: var(--primary-dark);
        }

        .messages-table {
            width: 100%;
            border-collapse: collapse;
            background-color: var(--white);
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border-radius: 8px;
            overflow: hidden;
        }

        .messages-table th,
        .messages-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .messages-table th {
            background-color: var(--primary);
            color: var(--white);
            font-weight: 600;
        }

        .messages-table tr:hover {
            background-color: var(--accent);
        }

        .message-subject {
            color: var(--primary);
            font-weight: 600;
        }

        .message-date {
            white-space: nowrap;
            color: var(--light-text);
            font-size: 14px;
        }

        .action-btn {
            padding: 5px 10px;
            background: none;
            border: 1px solid var(--border);
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .action-btn:hover {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .message-details {
            display: none;
            padding: 20px;
            background-color: var(--white);
            border-radius: 8px;
            margin-top: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .message-details.active {
            display: block;
        }

        .detail-row {
            margin-bottom: 15px;
        }

        .detail-label {
            font-weight: 600;
            color: var(--secondary);
            margin-bottom: 5px;
        }

        .detail-value {
            color: var(--text);
            line-height: 1.5;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--light-text);
        }

        .reply-count {
            display: inline-block;
            margin-left: 8px;
            padding: 2px 6px;
            background-color: var(--primary);
            color: white;
            border-radius: 12px;
            font-size: 12px;
        }

        .replies-list {
            margin-top: 20px;
        }

        .reply-item {
            padding: 12px;
            margin-bottom: 10px;
            background-color: var(--white);
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .reply-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .reply-admin {
            font-weight: 600;
            color: var(--primary);
        }

        .reply-date {
            color: var(--light-text);
        }

        .reply-content {
            line-height: 1.5;
        }

        @media (max-width: 768px) {
            .messages-table {
                display: block;
                overflow-x: auto;
            }
            
            .user-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
      
    <div class="user-container">
        <div class="user-header">
            <h1 class="user-title">My Contact Messages</h1>
            <a href="<?php echo ($_SESSION['user']['role'] == 'customer' ? 'customer_dashboard.php' :  'provider_dashboard.php'); ?>" class="back-btn">
    <i class="fas fa-arrow-left"></i> Back to Dashboard
</a>

        </div>

        <?php if (count($messages) > 0): ?>
            <table class="messages-table">
                <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Message</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($messages as $message): ?>
                        <tr>
                            <td class="message-subject">
                                <?= htmlspecialchars(ucfirst($message['subject'])) ?>
                                <?php if ($message['reply_count'] > 0): ?>
                                    <span class="reply-count"><?= $message['reply_count'] ?> reply<?= $message['reply_count'] > 1 ? 'ies' : '' ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars(substr($message['message'], 0, 50)) ?>...
                            </td>
                            <td class="message-date">
                                <?= date('M d, Y h:i A', strtotime($message['created_at'])) ?>
                            </td>
                            <td>
                                <?= $message['reply_count'] > 0 ? 'Replied' : 'Pending' ?>
                            </td>
                            <td>
                                <button class="action-btn view-message" data-id="<?= $message['id'] ?>">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="5" style="padding: 0;">
                                <div class="message-details" id="message-<?= $message['id'] ?>">
                                    <div class="detail-row">
                                        <div class="detail-label">Subject:</div>
                                        <div class="detail-value"><?= htmlspecialchars($message['subject']) ?></div>
                                    </div>
                                    
                                    <div class="detail-row">
                                        <div class="detail-label">Your Message:</div>
                                        <div class="detail-value"><?= nl2br(htmlspecialchars($message['message'])) ?></div>
                                    </div>
                                    
                                    <div class="detail-row">
                                        <div class="detail-label">Submitted:</div>
                                        <div class="detail-value"><?= date('F j, Y \a\t g:i A', strtotime($message['created_at'])) ?></div>
                                    </div>

                                    <!-- Replies Section -->
                                    <div class="replies-list">
                                        <?php 
                                        $replies = getMessageReplies($conn, $message['id']);
                                        if (count($replies) > 0): ?>
                                            <div class="detail-label">Admin Replies:</div>
                                            <?php foreach ($replies as $reply): ?>
                                                <div class="reply-item">
                                                    <div class="reply-header">
                                                        <span class="reply-admin"><?= htmlspecialchars($reply['admin_name']) ?> (Admin)</span>
                                                        <span class="reply-date"><?= date('M j, Y g:i A', strtotime($reply['created_at'])) ?></span>
                                                    </div>
                                                    <div class="reply-content"><?= nl2br(htmlspecialchars($reply['content'])) ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="detail-label">Status:</div>
                                            <div class="detail-value">No replies yet. Our team will respond soon.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox" style="font-size: 3rem; color: var(--light-text); margin-bottom: 15px;"></i>
                <h3>No messages yet</h3>
                <p>When you contact us through the contact form, your messages will appear here.</p>
                <a href="contact.php" class="back-btn" style="margin-top: 15px;">
                    <i class="fas fa-paper-plane"></i> Contact Us
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle message details
            document.querySelectorAll('.view-message').forEach(button => {
                button.addEventListener('click', function() {
                    const messageId = this.getAttribute('data-id');
                    const detailsDiv = document.getElementById(`message-${messageId}`);
                    detailsDiv.classList.toggle('active');
                });
            });
        });
    </script>
</body>
</html>