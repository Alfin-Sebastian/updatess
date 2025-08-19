<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

include 'db.php';

// Handle reply submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_message'])) {
    $message_id = filter_input(INPUT_POST, 'message_id', FILTER_VALIDATE_INT);
    $reply_content = $conn->real_escape_string($_POST['reply_content'] ?? '');
    $admin_id = $_SESSION['user']['id'];

    if ($message_id && $reply_content) {
        $stmt = $conn->prepare("INSERT INTO message_replies (message_id, admin_id, content, created_at) 
                               VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iis", $message_id, $admin_id, $reply_content);
        
        if ($stmt->execute()) {
            $success_message = "Reply sent successfully!";
        } else {
            $error_message = "Failed to send reply. Please try again.";
        }
        $stmt->close();
    }
}

// Get all contact messages with user info and replies
$query = "SELECT cm.*, u.name as user_name, u.email as user_email, 
                 (SELECT COUNT(*) FROM message_replies mr WHERE mr.message_id = cm.id) as reply_count
          FROM contact_messages cm
          LEFT JOIN users u ON cm.user_id = u.id
          ORDER BY cm.created_at DESC";
$messages = $conn->query($query)->fetch_all(MYSQLI_ASSOC);

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
    <title>Contact Messages | Admin Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        /* [Previous CSS remains exactly the same] */
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

        .admin-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--primary);
        }

        .admin-title {
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

        .message-user {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
        }

        .user-email {
            font-size: 12px;
            color: var(--light-text);
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

        @media (max-width: 768px) {
            .messages-table {
                display: block;
                overflow-x: auto;
            }
            
            .admin-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
        }

        /* New styles for reply feature */
        .reply-section {
            margin-top: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 8px;
            border-left: 3px solid var(--primary);
        }
        
        .reply-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .reply-form textarea {
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 4px;
            min-height: 100px;
        }
        
        .reply-submit {
            align-self: flex-start;
            padding: 8px 16px;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .reply-submit:hover {
            background-color: var(--primary-dark);
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
        
        .reply-count {
            display: inline-block;
            margin-left: 8px;
            padding: 2px 6px;
            background-color: var(--primary);
            color: white;
            border-radius: 12px;
            font-size: 12px;
        }
    </style>
</head>
<body>

    
    <div class="admin-container">
        <div class="admin-header">
            <h1 class="admin-title">Contact Messages</h1>
            <a href="admin_dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <?php if (isset($success_message)): ?>
            <div style="background-color: #38a16920; color: #38a169; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #38a169;">
                <i class="fas fa-check-circle"></i> <?= $success_message ?>
            </div>
        <?php elseif (isset($error_message)): ?>
            <div style="background-color: #e53e3e20; color: #e53e3e; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #e53e3e;">
                <i class="fas fa-exclamation-circle"></i> <?= $error_message ?>
            </div>
        <?php endif; ?>

        <?php if (count($messages) > 0): ?>
            <table class="messages-table">
                <thead>
                    <tr>
                        <th>From</th>
                        <th>Subject</th>
                        <th>Message</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($messages as $message): ?>
                        <tr>
                            <td>
                                <div class="message-user">
                                    <?php if ($message['user_id']): ?>
                                        <span class="user-name"><?= htmlspecialchars($message['user_name']) ?></span>
                                        <span class="user-email"><?= htmlspecialchars($message['user_email']) ?></span>
                                    <?php else: ?>
                                        <span class="user-name"><?= htmlspecialchars($message['name']) ?></span>
                                        <span class="user-email"><?= htmlspecialchars($message['email']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
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
                                <button class="action-btn view-message" data-id="<?= $message['id'] ?>">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="5" style="padding: 0;">
                                <div class="message-details" id="message-<?= $message['id'] ?>">
                                    <div class="detail-row">
                                        <div class="detail-label">Full Message:</div>
                                        <div class="detail-value"><?= nl2br(htmlspecialchars($message['message'])) ?></div>
                                    </div>
                                    
                                    <?php if ($message['phone']): ?>
                                    <div class="detail-row">
                                        <div class="detail-label">Phone:</div>
                                        <div class="detail-value"><?= htmlspecialchars($message['phone']) ?></div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="detail-row">
                                        <div class="detail-label">Received:</div>
                                        <div class="detail-value"><?= date('F j, Y \a\t g:i A', strtotime($message['created_at'])) ?></div>
                                    </div>

                                    <!-- Replies Section -->
                                    <div class="replies-list">
                                        <?php 
                                        $replies = getMessageReplies($conn, $message['id']);
                                        if (count($replies) > 0): ?>
                                            <div class="detail-label">Replies:</div>
                                            <?php foreach ($replies as $reply): ?>
                                                <div class="reply-item">
                                                    <div class="reply-header">
                                                        <span class="reply-admin"><?= htmlspecialchars($reply['admin_name']) ?></span>
                                                        <span class="reply-date"><?= date('M j, Y g:i A', strtotime($reply['created_at'])) ?></span>
                                                    </div>
                                                    <div class="reply-content"><?= nl2br(htmlspecialchars($reply['content'])) ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Reply Form -->
                                    <div class="reply-section">
                                        <form class="reply-form" method="POST">
                                            <input type="hidden" name="message_id" value="<?= $message['id'] ?>">
                                            <textarea name="reply_content" placeholder="Type your reply here..." required></textarea>
                                            <button type="submit" name="reply_message" class="reply-submit">
                                                <i class="fas fa-paper-plane"></i> Send Reply
                                            </button>
                                        </form>
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
                <p>When users contact you through the contact form, their messages will appear here.</p>
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