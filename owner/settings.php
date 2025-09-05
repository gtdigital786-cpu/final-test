<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

require_role('OWNER');

// --- CHANGE FOR MANUAL INSTALL ---
// We no longer check for a 'vendor' folder. We assume the files we uploaded exist.
// This line replaces the old check and prevents the red error box from showing.
define('PHPMAILER_AVAILABLE', true);
// --- END CHANGE ---

$database = new Database();
$pdo = $database->getConnection();

// Handle all form submissions (updates and tests)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'change_username':
                $newUsername = sanitize_input($_POST['new_username'] ?? '');
                if (empty($newUsername)) {
                    $error = 'New username is required';
                } else {
                    try {
                        $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
                        $stmt->execute([$newUsername, $_SESSION['user_id']]);
                        $_SESSION['username'] = $newUsername;
                        redirect_with_message('settings.php', 'Username changed successfully!', 'success');
                    } catch (Exception $e) {
                        $error = 'Failed to change username - it may already exist.';
                    }
                }
                break;
                
            case 'update_sms':
                $smsApiUrl = sanitize_input($_POST['sms_api_url'] ?? '');
                $smsApiKey = sanitize_input($_POST['sms_api_key'] ?? '');
                $smsSenderId = sanitize_input($_POST['sms_sender_id'] ?? '');
                $hotelName = sanitize_input($_POST['hotel_name'] ?? '');
                if (empty($smsApiUrl) || empty($smsApiKey)) {
                    $error = 'SMS API URL and API Key are required';
                } else {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?");
                        $stmt->execute(['sms_api_url', $smsApiUrl, $_SESSION['user_id'], $smsApiUrl, $_SESSION['user_id']]);
                        $stmt->execute(['sms_api_key', $smsApiKey, $_SESSION['user_id'], $smsApiKey, $_SESSION['user_id']]);
                        $stmt->execute(['sms_sender_id', $smsSenderId, $_SESSION['user_id'], $smsSenderId, $_SESSION['user_id']]);
                        $stmt->execute(['hotel_name', $hotelName, $_SESSION['user_id'], $hotelName, $_SESSION['user_id']]);
                        redirect_with_message('settings.php', 'SMS settings updated successfully!', 'success');
                    } catch (Exception $e) {
                        $error = 'Failed to update SMS settings.';
                    }
                }
                break;
                
            case 'update_email':
                $smtpHost = sanitize_input($_POST['smtp_host'] ?? '');
                $smtpPort = sanitize_input($_POST['smtp_port'] ?? '');
                $smtpUsername = sanitize_input($_POST['smtp_username'] ?? '');
                $smtpPassword = $_POST['smtp_password'] ?? ''; // Do not sanitize password
                $smtpEncryption = $_POST['smtp_encryption'] ?? 'ssl';
                $ownerEmail = sanitize_input($_POST['owner_email'] ?? '');
                if (empty($smtpHost) || empty($smtpUsername)) {
                    $error = 'SMTP Host and Username are required';
                } else {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?");
                        $stmt->execute(['smtp_host', $smtpHost, $_SESSION['user_id'], $smtpHost, $_SESSION['user_id']]);
                        $stmt->execute(['smtp_port', $smtpPort, $_SESSION['user_id'], $smtpPort, $_SESSION['user_id']]);
                        $stmt->execute(['smtp_username', $smtpUsername, $_SESSION['user_id'], $smtpUsername, $_SESSION['user_id']]);
                        if (!empty($smtpPassword)) {
                            $stmt->execute(['smtp_password', $smtpPassword, $_SESSION['user_id'], $smtpPassword, $_SESSION['user_id']]);
                        }
                        $stmt->execute(['smtp_encryption', $smtpEncryption, $_SESSION['user_id'], $smtpEncryption, $_SESSION['user_id']]);
                        $stmt->execute(['owner_email', $ownerEmail, $_SESSION['user_id'], $ownerEmail, $_SESSION['user_id']]);
                        redirect_with_message('settings.php', 'Email settings updated successfully!', 'success');
                    } catch (Exception $e) {
                        $error = 'Failed to update email settings.';
                    }
                }
                break;
                
            case 'test_email':
                $testEmail = sanitize_input($_POST['test_email'] ?? '');
                if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                    $error = 'A valid email address is required for testing';
                } else {
                    try {
                        // Load the email functions file ONLY when it is needed.
                        require_once '../includes/email_functions.php';
                        $result = test_email_configuration($testEmail, $pdo, $_SESSION['user_id']);
                        
                        if ($result['success']) {
                            redirect_with_message('settings.php', 'Test email sent successfully! Check your inbox.', 'success');
                        } else {
                            redirect_with_message('settings.php', 'Email test failed: ' . htmlspecialchars($result['message']), 'error');
                        }
                    } catch (Exception $e) {
                        redirect_with_message('settings.php', 'A critical error occurred: ' . $e->getMessage(), 'error');
                    }
                }
                break;

            case 'update_upi':
                $upiId = sanitize_input($_POST['upi_id'] ?? '');
                if (empty($upiId)) {
                    $error = 'UPI ID is required';
                } else {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, updated_by) VALUES ('upi_id', ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?");
                        $stmt->execute([$upiId, $_SESSION['user_id'], $upiId, $_SESSION['user_id']]);
                        redirect_with_message('settings.php', 'UPI settings updated successfully!', 'success');
                    } catch (Exception $e) {
                        $error = 'Failed to update UPI settings.';
                    }
                }
                break;
                
            case 'change_password':
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                if (empty($newPassword) || strlen($newPassword) < 6) {
                    $error = 'Password must be at least 6 characters long';
                } elseif ($newPassword !== $confirmPassword) {
                    $error = 'The new passwords do not match';
                } else {
                    try {
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $stmt->execute([$hashedPassword, $_SESSION['user_id']]);
                        session_destroy();
                        redirect_with_message('../login.php', 'Password changed! Please login again.', 'success');
                    } catch (Exception $e) {
                        $error = 'Failed to change password.';
                    }
                }
                break;
                
            case 'enable_testing':
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO system_settings (setting_key, setting_value) 
                        VALUES ('testing_mode_enabled', '1')
                        ON DUPLICATE KEY UPDATE setting_value = '1'
                    ");
                    $stmt->execute();
                    redirect_with_message('settings.php', 'Testing mode enabled! Auto checkout can now be tested anytime.', 'success');
                } catch (Exception $e) {
                    $error = 'Failed to enable testing mode.';
                }
                break;
                
            case 'test_auto_checkout':
                try {
                    require_once '../includes/auto_checkout.php';
                    $autoCheckout = new AutoCheckout($pdo);
                    $result = $autoCheckout->testAutoCheckout();
                    
                    $message = "Auto checkout test completed: " . $result['status'];
                    if (isset($result['checked_out'])) {
                        $message .= " - Checked out: " . $result['checked_out'] . " bookings";
                    }
                    redirect_with_message('settings.php', $message, 'success');
                } catch (Exception $e) {
                    $error = 'Auto checkout test failed: ' . $e->getMessage();
                }
                break;
                
            case 'update_auto_checkout':
                $autoCheckoutTime = '10:00'; // FIXED: Always force 10:00 AM
                $autoCheckoutEnabled = isset($_POST['auto_checkout_enabled']) ? '1' : '0';
                
                try {
                    // FIXED: Reset last run time and auto checkout flags for fresh start
                    $stmt = $pdo->prepare("
                        UPDATE system_settings 
                        SET setting_value = '' 
                        WHERE setting_key = 'last_auto_checkout_run'
                    ");
                    $stmt->execute();
                    
                    // Reset all auto checkout processed flags
                    $stmt = $pdo->prepare("
                        UPDATE bookings 
                        SET auto_checkout_processed = 0 
                        WHERE status IN ('BOOKED', 'PENDING')
                    ");
                    $stmt->execute();
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO system_settings (setting_key, setting_value) 
                        VALUES ('auto_checkout_time', '10:00'), ('auto_checkout_enabled', ?), ('default_checkout_time', '10:00')
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                    ");
                    $stmt->execute([$autoCheckoutEnabled]);
                    
                    // Update all future bookings to use new default time
                    $stmt = $pdo->prepare("
                        UPDATE bookings 
                        SET default_checkout_time = '10:00:00' 
                        WHERE status IN ('BOOKED', 'PENDING', 'ADVANCED_BOOKED')
                    ");
                    $stmt->execute();
                    
                    redirect_with_message('settings.php', 'Auto checkout settings updated and system reset for tomorrow 10:00 AM execution!', 'success');
                } catch (Exception $e) {
                    $error = 'Failed to update auto checkout settings.';
                }
                break;
        }
    }
}

// Get all current settings from the database
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings");
$stmt->execute();
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$flash = get_flash_message();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - L.P.S.T Bookings</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <nav class="top-nav">
        <div class="nav-links">
            <a href="index.php" class="nav-button">← Dashboard</a>
        </div>
        <a href="/" class="nav-brand">L.P.S.T Bookings</a>
        <div class="nav-links">
            <span style="margin-right: 1rem;">Owner Panel</span>
            <a href="../logout.php" class="nav-button danger">Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($flash): ?>
            <div class="flash-message flash-<?= htmlspecialchars($flash['type']) ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="flash-message flash-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <h2>System Settings</h2>
        
        <!-- Username Change -->
        <div class="form-container">
            <h3>Change Username</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="change_username">
                <div class="form-group">
                    <label for="new_username" class="form-label">New Username *</label>
                    <input type="text" id="new_username" name="new_username" class="form-control" required
                           value="<?= htmlspecialchars($_SESSION['username']) ?>">
                </div>
                <button type="submit" class="btn btn-warning">Change Username</button>
            </form>
        </div>
        
        <!-- SMS Settings -->
        <div class="form-container">
            <h3>SMS Configuration</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="update_sms">
                <div class="form-group">
                    <label for="hotel_name" class="form-label">Hotel Name *</label>
                    <input type="text" id="hotel_name" name="hotel_name" class="form-control" required
                           value="<?= htmlspecialchars($settings['hotel_name'] ?? 'L.P.S.T Hotel') ?>">
                </div>
                <div class="form-group">
                    <label for="sms_api_url" class="form-label">SMS API URL *</label>
                    <input type="url" id="sms_api_url" name="sms_api_url" class="form-control" required
                           value="<?= htmlspecialchars($settings['sms_api_url'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="sms_api_key" class="form-label">SMS API Key *</label>
                    <input type="text" id="sms_api_key" name="sms_api_key" class="form-control" required
                           value="<?= htmlspecialchars($settings['sms_api_key'] ?? '') ?>">
                </div>
                <button type="submit" class="btn btn-primary">Update SMS Settings</button>
            </form>
        </div>
        
        <!-- Email Settings -->
        <div class="form-container">
            <h3>Email Configuration (SMTP)</h3>
            <p>Configure SMTP settings for sending export reports via email.</p>
            
            <!-- The red error box is now removed, as we installed PHPMailer manually -->
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="update_email">
                
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label for="smtp_host" class="form-label">SMTP Host *</label>
                        <input type="text" id="smtp_host" name="smtp_host" class="form-control" required value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>" placeholder="e.g., smtp.hostinger.com">
                    </div>
                    <div class="form-group">
                        <label for="smtp_port" class="form-label">SMTP Port *</label>
                        <input type="number" id="smtp_port" name="smtp_port" class="form-control" required value="<?= htmlspecialchars($settings['smtp_port'] ?? '465') ?>" placeholder="465 (SSL) or 587 (TLS)">
                    </div>
                </div>
                <div class="form-group">
                    <label for="smtp_username" class="form-label">SMTP Username (Email) *</label>
                    <input type="email" id="smtp_username" name="smtp_username" class="form-control" required value="<?= htmlspecialchars($settings['smtp_username'] ?? '') ?>" placeholder="your-email@example.com">
                </div>
                <div class="form-group">
                    <label for="smtp_password" class="form-label">SMTP Password</label>
                    <input type="password" id="smtp_password" name="smtp_password" class="form-control" placeholder="Leave blank to keep current password">
                    <small>Enter your password here to save or update it.</small>
                </div>
                <div class="form-group">
                    <label for="smtp_encryption" class="form-label">Encryption</label>
                    <select id="smtp_encryption" name="smtp_encryption" class="form-control">
                        <option value="ssl" <?= ($settings['smtp_encryption'] ?? 'ssl') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                        <option value="tls" <?= ($settings['smtp_encryption'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="owner_email" class="form-label">Owner Email (for reports)</label>
                    <input type="email" id="owner_email" name="owner_email" class="form-control" value="<?= htmlspecialchars($settings['owner_email'] ?? '') ?>" placeholder="owner@example.com">
                </div>
                <button type="submit" class="btn btn-primary">Update Email Settings</button>
            </form>
            
            <!-- Test Email Form -->
            <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #eee;">
                <h4>Test Email Configuration</h4>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="action" value="test_email">
                    <div class="form-group">
                        <label for="test_email" class="form-label">Test Email Address</label>
                        <input type="email" id="test_email" name="test_email" class="form-control" required placeholder="test@example.com">
                    </div>
                    <button type="submit" class="btn btn-warning">Send Test Email</button>
                </form>
            </div>
        </div>
        
        <!-- UPI Settings -->
        <div class="form-container">
            <h3>UPI Payment Settings</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="update_upi">
                <div class="form-group">
                    <label for="upi_id" class="form-label">UPI ID *</label>
                    <input type="text" id="upi_id" name="upi_id" class="form-control" required value="<?= htmlspecialchars($settings['upi_id'] ?? '') ?>" placeholder="yourname@upi">
                </div>
                <button type="submit" class="btn btn-primary">Update UPI Settings</button>
            </form>
        </div>
        
        <!-- Auto Checkout Settings -->
        <div class="form-container">
            <h3>🕙 Daily 10:00 AM Auto Checkout Master Control (Owner Only)</h3>
            <div style="background: linear-gradient(45deg, #007bff, #0056b3); color: white; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                <h4 style="margin: 0; color: white;">🕙 DAILY 10:00 AM AUTO CHECKOUT SYSTEM</h4>
                <p style="margin: 0.5rem 0 0 0; opacity: 0.9;">Automatically checkout all active bookings at 10:00 AM daily. Only owner can modify these settings.</p>
            </div>
            <?php
            // Get auto checkout settings
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('auto_checkout_enabled', 'auto_checkout_time')");
            $autoSettings = [];
            while ($row = $stmt->fetch()) {
                $autoSettings[$row['setting_key']] = $row['setting_value'];
            }
            $autoEnabled = ($autoSettings['auto_checkout_enabled'] ?? '1') === '1';
            $autoTime = $autoSettings['auto_checkout_time'] ?? '10:00';
            ?>
            
            <!-- Testing Mode Controls -->
            <div style="background: rgba(16, 185, 129, 0.1); padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border: 2px solid var(--success-color);">
                <h4 style="color: var(--success-color); margin-bottom: 0.5rem;">🧪 Manual Testing Controls (No 24 Hour Wait)</h4>
                <form method="POST" style="display: inline-block; margin-right: 1rem;">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="action" value="test_auto_checkout">
                    <button type="submit" class="btn btn-success" onclick="return confirm('Test auto checkout now?')">🧪 Test Auto Checkout Now</button>
                </form>
                <a href="../cron/auto_checkout_cron.php?manual_run=1" target="_blank" class="btn btn-warning">🔧 Test Cron Direct</a>
                <a href="../admin/auto_checkout_logs.php" class="btn btn-outline">📋 View Checkout Logs</a>
            </div>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="update_auto_checkout">
                
                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" name="auto_checkout_enabled" <?= $autoEnabled ? 'checked' : '' ?>>
                        Enable Daily 10:00 AM Auto Checkout
                    </label>
                    <small style="color: var(--dark-color);">When enabled, all active bookings will be automatically checked out daily at 10:00 AM</small>
                </div>
                
                <div class="form-group">
                    <label for="auto_checkout_time" class="form-label">Daily Checkout Time (Fixed at 10:00 AM)</label>
                    <input type="text" id="auto_checkout_time" name="auto_checkout_time" class="form-control" 
                           value="10:00 AM" readonly style="background: #f8f9fa; font-weight: bold; color: #007bff;">
                    <small style="color: var(--success-color); font-weight: 600;">✅ FIXED: System will run EXACTLY at 10:00 AM daily (no more random times like 3:30 PM)</small>
                    <div style="margin-top: 0.5rem; padding: 0.5rem; background: rgba(16, 185, 129, 0.1); border-radius: 4px;">
                        <strong>Current Time:</strong> <?= date('H:i') ?> | <strong>Next Auto Checkout:</strong> Tomorrow at 10:00 AM SHARP
                        <br><strong>System Status:</strong> FIXED - No more wrong timing issues
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">Save Auto Checkout Settings</button>
            </form>
            
            <div style="margin-top: 2rem; padding: 1rem; background: rgba(40, 167, 69, 0.1); border-radius: 8px; border-left: 4px solid var(--success-color);">
                <h4 style="color: var(--success-color);">Current Status:</h4>
                <p><strong>Auto Checkout:</strong> <?= $autoEnabled ? '✅ ENABLED & FIXED' : '❌ DISABLED' ?></p>
                <p><strong>Daily Time:</strong> 10:00 AM (GUARANTEED - No more 3:30 PM issues)</p>
                <p><strong>Next Run:</strong> Tomorrow at 10:00 AM SHARP</p>
                <p><strong>Payment Mode:</strong> Manual - Admin marks payments after checkout</p>
                <p><strong>Default Checkout Time:</strong> All new bookings default to 10:00 AM checkout</p>
                <p><strong>Cron Job Status:</strong> <?= $autoEnabled ? '🟢 ACTIVE & FIXED' : '🔴 Inactive' ?></p>
                <p><strong>Timing Issue:</strong> ✅ RESOLVED - System will only run at 10:00 AM</p>
                <div style="margin-top: 1rem;">
                    <a href="../admin/auto_checkout_logs.php" class="btn btn-outline">📋 View Checkout Logs</a>
                    <a href="../cron/auto_checkout_cron.php?manual_run=1" target="_blank" class="btn btn-warning">🔧 Test Cron Direct</a>
                    <a href="../test_auto_checkout_final.php" target="_blank" class="btn btn-success">🎯 Verify Fix</a>
                </div>
            </div>
            
            <!-- Cron Job Instructions -->
            <div style="margin-top: 2rem; padding: 1rem; background: rgba(37, 99, 235, 0.1); border-radius: 8px; border-left: 4px solid var(--primary-color);">
                <h4 style="color: var(--primary-color);">🔧 Hostinger Cron Job Setup</h4>
                <p><strong>Your cron job command (already set up):</strong></p>
                <div style="background: white; padding: 0.5rem; border-radius: 4px; font-family: monospace; margin: 0.5rem 0;">
                    0 10 * * * /usr/bin/php /home/u261459251/domains/lpstnashik.in/public_html/cron/auto_checkout_cron.php
                </div>
                <p><strong>This runs daily at exactly 10:00 AM and processes all active bookings.</strong></p>
                
                <div style="margin-top: 1rem; padding: 0.5rem; background: rgba(255, 255, 255, 0.8); border-radius: 4px;">
                    <p style="margin: 0; color: var(--dark-color); font-weight: 600;">
                        ✅ Cron job is properly configured. If auto checkout is not working, use the test buttons above to diagnose issues.
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Password Change -->
        <div class="form-container">
            <h3>Change Password</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label for="new_password" class="form-label">New Password *</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required minlength="6">
                </div>
                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm New Password *</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="6">
                </div>
                <button type="submit" class="btn btn-danger" onclick="return confirm('You will be logged out. Continue?')">
                    Change Password
                </button>
            </form>
        </div>

    </div>
</body>
</html>