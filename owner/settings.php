<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

require_role('OWNER');

$database = new Database();
$pdo = $database->getConnection();

// Handle all form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'update_auto_checkout':
                $autoCheckoutEnabled = isset($_POST['auto_checkout_enabled']) ? '1' : '0';
                
                try {
                    // Reset system for fresh start
                    $stmt = $pdo->prepare("DELETE FROM system_settings WHERE setting_key LIKE '%auto_checkout%'");
                    $stmt->execute();
                    
                    // Reset all booking flags
                    $stmt = $pdo->prepare("
                        UPDATE bookings 
                        SET auto_checkout_processed = 0,
                            actual_checkout_date = NULL,
                            actual_checkout_time = NULL,
                            default_checkout_time = '10:00:00',
                            is_auto_checkout_eligible = 1
                        WHERE status IN ('BOOKED', 'PENDING')
                    ");
                    $stmt->execute();
                    
                    // Insert fresh settings
                    $settings = [
                        'auto_checkout_enabled' => $autoCheckoutEnabled,
                        'auto_checkout_time' => '10:00',
                        'auto_checkout_timezone' => 'Asia/Kolkata',
                        'auto_checkout_last_run_date' => '',
                        'auto_checkout_last_run_time' => '',
                        'auto_checkout_execution_window_start' => '10:00',
                        'auto_checkout_execution_window_end' => '10:05',
                        'auto_checkout_force_daily_execution' => '1',
                        'auto_checkout_system_version' => '2.0'
                    ];
                    
                    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
                    foreach ($settings as $key => $value) {
                        $stmt->execute([$key, $value]);
                    }
                    
                    redirect_with_message('settings.php', 'Auto checkout system reset and configured for daily 10:00 AM execution!', 'success');
                } catch (Exception $e) {
                    $error = 'Failed to update auto checkout settings: ' . $e->getMessage();
                }
                break;
                
            case 'test_auto_checkout':
                try {
                    require_once '../includes/auto_checkout.php';
                    $autoCheckout = new AutoCheckout($pdo);
                    $result = $autoCheckout->testAutoCheckout();
                    
                    $message = "Test completed: " . $result['status'] . " - Processed: " . ($result['total_processed'] ?? 0) . " bookings (NO payment calculation)";
                    redirect_with_message('settings.php', $message, 'success');
                } catch (Exception $e) {
                    $error = 'Auto checkout test failed: ' . $e->getMessage();
                }
                break;
                
            case 'force_checkout_all':
                try {
                    require_once '../includes/auto_checkout.php';
                    $autoCheckout = new AutoCheckout($pdo);
                    $result = $autoCheckout->forceCheckoutAll();
                    
                    $message = "Force checkout completed: " . $result['status'] . " - Processed: " . ($result['total_processed'] ?? 0) . " bookings (Admin will mark payments)";
                    redirect_with_message('settings.php', $message, 'success');
                } catch (Exception $e) {
                    $error = 'Force checkout failed: ' . $e->getMessage();
                }
                break;
                
            case 'reset_system':
                try {
                    // Complete system reset
                    $pdo->exec("DELETE FROM auto_checkout_logs WHERE DATE(created_at) = CURDATE()");
                    $pdo->exec("DELETE FROM cron_execution_logs WHERE execution_date = CURDATE()");
                    $pdo->exec("UPDATE bookings SET auto_checkout_processed = 0 WHERE status IN ('BOOKED', 'PENDING')");
                    $pdo->exec("UPDATE system_settings SET setting_value = '' WHERE setting_key IN ('auto_checkout_last_run_date', 'auto_checkout_last_run_time')");
                    
                    redirect_with_message('settings.php', 'System reset completed! Ready for fresh 10:00 AM execution tomorrow.', 'success');
                } catch (Exception $e) {
                    $error = 'System reset failed: ' . $e->getMessage();
                }
                break;
        }
    }
}

// Get current settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE '%auto_checkout%'");
$autoSettings = [];
while ($row = $stmt->fetch()) {
    $autoSettings[$row['setting_key']] = $row['setting_value'];
}

$autoEnabled = ($autoSettings['auto_checkout_enabled'] ?? '1') === '1';
$autoTime = $autoSettings['auto_checkout_time'] ?? '10:00';
$lastRunDate = $autoSettings['auto_checkout_last_run_date'] ?? '';
$lastRunTime = $autoSettings['auto_checkout_last_run_time'] ?? '';

// Get active bookings count
$stmt = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status IN ('BOOKED', 'PENDING') AND auto_checkout_processed = 0");
$activeBookingsCount = $stmt->fetchColumn();

// Get today's execution status
$stmt = $pdo->prepare("
    SELECT * FROM cron_execution_logs 
    WHERE execution_date = CURDATE() 
    ORDER BY execution_time DESC 
    LIMIT 1
");
$stmt->execute();
$todayExecution = $stmt->fetch();

$flash = get_flash_message();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto Checkout Settings - L.P.S.T Bookings</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .system-status {
            padding: 1.5rem;
            border-radius: 10px;
            margin: 1rem 0;
            text-align: center;
            font-weight: bold;
        }
        .status-active {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
            animation: pulse 3s infinite;
        }
        .status-inactive {
            background: linear-gradient(45deg, #dc3545, #c82333);
            color: white;
        }
        .status-warning {
            background: linear-gradient(45deg, #ffc107, #e0a800);
            color: black;
        }
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.8; }
            100% { opacity: 1; }
        }
        .test-controls {
            background: rgba(16, 185, 129, 0.1);
            border: 2px solid var(--success-color);
            border-radius: 10px;
            padding: 1.5rem;
            margin: 1rem 0;
        }
        .danger-zone {
            background: rgba(239, 68, 68, 0.1);
            border: 2px solid var(--danger-color);
            border-radius: 10px;
            padding: 1.5rem;
            margin: 1rem 0;
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <div class="nav-links">
            <a href="index.php" class="nav-button">← Dashboard</a>
            <a href="admins.php" class="nav-button">Admins</a>
            <a href="reports.php" class="nav-button">Reports</a>
        </div>
        <a href="/" class="nav-brand">L.P.S.T Bookings</a>
        <div class="nav-links">
            <span style="margin-right: 1rem;">Owner Panel</span>
            <a href="../logout.php" class="nav-button danger">Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($flash): ?>
            <div class="flash-message flash-<?= $flash['type'] ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="flash-message flash-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <h2>🕙 Daily 10:00 AM Auto Checkout Master Control</h2>
        
        <!-- System Status Display -->
        <div class="system-status <?= $autoEnabled ? 'status-active' : 'status-inactive' ?>">
            <h3>🕙 AUTO CHECKOUT SYSTEM STATUS</h3>
            <p>Status: <?= $autoEnabled ? '✅ ENABLED' : '❌ DISABLED' ?></p>
            <p>Daily Execution Time: 10:00 AM (Asia/Kolkata)</p>
            <p>Current Server Time: <?= date('H:i:s') ?></p>
            <p>Active Bookings Ready: <?= $activeBookingsCount ?></p>
            <?php if ($lastRunDate): ?>
                <p>Last Execution: <?= $lastRunDate ?> at <?= $lastRunTime ?></p>
            <?php endif; ?>
            <?php if ($todayExecution): ?>
                <p>Today's Status: <?= strtoupper($todayExecution['execution_status']) ?> 
                   (<?= $todayExecution['bookings_successful'] ?> successful, <?= $todayExecution['bookings_failed'] ?> failed)</p>
            <?php else: ?>
                <p>Today's Status: NOT EXECUTED YET</p>
            <?php endif; ?>
        </div>
        
        <!-- Auto Checkout Configuration -->
        <div class="form-container">
            <h3>Auto Checkout Configuration</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="update_auto_checkout">
                
                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" name="auto_checkout_enabled" <?= $autoEnabled ? 'checked' : '' ?>>
                        Enable Daily 10:00 AM Auto Checkout
                    </label>
                    <small style="color: var(--dark-color);">When enabled, all active bookings will be automatically checked out daily at exactly 10:00 AM</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Daily Checkout Time (FIXED)</label>
                    <input type="text" class="form-control" value="10:00 AM (FIXED)" readonly 
                           style="background: #f8f9fa; font-weight: bold; color: #007bff;">
                    <small style="color: var(--success-color); font-weight: 600;">
                        ✅ FIXED: System will run EXACTLY at 10:00 AM daily (execution window: 10:00-10:05 AM)
                    </small>
                </div>
                
                <button type="submit" class="btn btn-primary">Save Auto Checkout Settings</button>
            </form>
        </div>
        
        <!-- Manual Test Controls -->
        <div class="test-controls">
            <h3 style="color: var(--success-color);">🧪 Manual Test Controls (No Wait Required)</h3>
            <p>Test the auto checkout system immediately without waiting for 10:00 AM:</p>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin: 1rem 0;">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="action" value="test_auto_checkout">
                    <button type="submit" class="btn btn-success" style="width: 100%; padding: 1rem;">
                        🧪 Test Auto Checkout Now
                    </button>
                    <small style="display: block; margin-top: 0.5rem; color: var(--dark-color);">
                        Tests the system with current settings
                    </small>
                </form>
                
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="action" value="force_checkout_all">
                    <button type="submit" class="btn btn-warning" style="width: 100%; padding: 1rem;"
                            onclick="return confirm('This will force checkout ALL active bookings immediately. Continue?')">
                        🚨 Force Checkout All
                    </button>
                    <small style="display: block; margin-top: 0.5rem; color: var(--dark-color);">
                        Immediately processes all bookings
                    </small>
                </form>
            </div>
            
            <div style="margin-top: 1rem;">
                <a href="../cron/auto_checkout_cron.php?manual_run=1" target="_blank" class="btn btn-outline">
                    🔗 Test Cron Script Directly
                </a>
                <a href="../admin/auto_checkout_logs.php" class="btn btn-outline">
                    📋 View Checkout Logs
                </a>
            </div>
        </div>
        
        <!-- System Reset (Danger Zone) -->
        <div class="danger-zone">
            <h3 style="color: var(--danger-color);">🚨 System Reset (Danger Zone)</h3>
            <p>Use this if auto checkout is still not working properly:</p>
            
            <form method="POST" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="reset_system">
                <button type="submit" class="btn btn-danger"
                        onclick="return confirm('This will reset the entire auto checkout system. All today\'s logs will be cleared and flags reset. Continue?')">
                    🔄 Complete System Reset
                </button>
            </form>
            <small style="display: block; margin-top: 0.5rem; color: var(--dark-color);">
                Clears all flags, logs, and prepares system for fresh execution
            </small>
        </div>
        
        <!-- Current System Information -->
        <div class="form-container">
            <h3>Current System Information</h3>
            <div style="background: rgba(37, 99, 235, 0.1); padding: 1.5rem; border-radius: 8px;">
                <h4 style="color: var(--primary-color);">System Configuration:</h4>
                <ul>
                    <li><strong>Auto Checkout:</strong> <?= $autoEnabled ? '✅ ENABLED' : '❌ DISABLED' ?></li>
                    <li><strong>Execution Time:</strong> 10:00 AM (FIXED)</li>
                    <li><strong>Execution Window:</strong> 10:00-10:05 AM</li>
                    <li><strong>Timezone:</strong> Asia/Kolkata</li>
                    <li><strong>Current Time:</strong> <?= date('H:i:s') ?></li>
                    <li><strong>Active Bookings:</strong> <?= $activeBookingsCount ?></li>
                    <li><strong>Payment Mode:</strong> MANUAL ONLY (No automatic calculation)</li>
                    <li><strong>SMS Notifications:</strong> Enabled</li>
                </ul>
                
                <?php if ($todayExecution): ?>
                    <h4 style="color: var(--primary-color);">Today's Execution:</h4>
                    <ul>
                        <li><strong>Status:</strong> <?= strtoupper($todayExecution['execution_status']) ?></li>
                        <li><strong>Time:</strong> <?= $todayExecution['execution_time'] ?></li>
                        <li><strong>Bookings Found:</strong> <?= $todayExecution['bookings_found'] ?></li>
                        <li><strong>Successful:</strong> <?= $todayExecution['bookings_successful'] ?></li>
                        <li><strong>Failed:</strong> <?= $todayExecution['bookings_failed'] ?></li>
                        <?php if ($todayExecution['error_message']): ?>
                            <li><strong>Error:</strong> <?= htmlspecialchars($todayExecution['error_message']) ?></li>
                        <?php endif; ?>
                    </ul>
                <?php else: ?>
                    <h4 style="color: var(--warning-color);">Today's Execution:</h4>
                    <p>Auto checkout has not executed today yet. Next execution: Tomorrow at 10:00 AM</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Hostinger Cron Job Instructions -->
        <div class="form-container">
            <h3>🔧 Hostinger Cron Job Setup</h3>
            <div style="background: rgba(37, 99, 235, 0.1); padding: 1.5rem; border-radius: 8px;">
                <h4>Your Cron Job Command (Copy this exactly):</h4>
                <div style="background: white; padding: 1rem; border-radius: 4px; font-family: monospace; margin: 0.5rem 0; border: 2px solid #007bff;">
                    0 10 * * * /usr/bin/php /home/u261459251/domains/lpstnashik.in/public_html/cron/auto_checkout_cron.php
                </div>
                
                <h4>Setup Instructions:</h4>
                <ol>
                    <li>Login to your Hostinger control panel</li>
                    <li>Go to "Advanced" → "Cron Jobs"</li>
                    <li>Click "Create Cron Job"</li>
                    <li>Set schedule: <strong>0 10 * * *</strong></li>
                    <li>Set command: <strong>/usr/bin/php /home/u261459251/domains/lpstnashik.in/public_html/cron/auto_checkout_cron.php</strong></li>
                    <li>Save the cron job</li>
                </ol>
                
                <div style="background: rgba(40, 167, 69, 0.1); padding: 1rem; border-radius: 4px; margin-top: 1rem;">
                    <p style="margin: 0; color: var(--success-color); font-weight: 600;">
                        ✅ This will execute EXACTLY at 10:00 AM every day and process all active bookings automatically.
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Troubleshooting -->
        <div class="form-container">
            <h3>🔍 Troubleshooting</h3>
            <div style="background: rgba(255, 193, 7, 0.1); padding: 1.5rem; border-radius: 8px;">
                <h4>If auto checkout is still not working:</h4>
                <ol>
                    <li><strong>Check Cron Job:</strong> Verify it's active in Hostinger control panel</li>
                    <li><strong>Test Manually:</strong> Use the test buttons above</li>
                    <li><strong>Check Logs:</strong> View auto checkout logs for error messages</li>
                    <li><strong>Reset System:</strong> Use the system reset button above</li>
                    <li><strong>Verify Time:</strong> Ensure server time matches Asia/Kolkata</li>
                </ol>
                
                <h4>System Requirements:</h4>
                <ul>
                    <li>✅ Cron job must run at exactly 10:00 AM</li>
                    <li>✅ Database must have all required tables</li>
                    <li>✅ Auto checkout must be enabled</li>
                    <li>✅ Active bookings must exist</li>
                    <li>✅ System must not have executed today already</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>