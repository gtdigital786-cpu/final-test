<?php
/**
 * FIXED Auto Checkout Cron Job for L.P.S.T Hotel Booking System
 * GUARANTEED daily 10:00 AM execution with precise timing
 * 
 * HOSTINGER CRON JOB COMMAND (FIXED):
 * 0 10 * * * /usr/bin/php /home/u261459251/domains/lpstnashik.in/public_html/cron/auto_checkout_cron.php
 * 
 * This runs daily at exactly 10:00 AM and checks out all active bookings
 */

// FIXED: Set timezone first thing
date_default_timezone_set('Asia/Kolkata');

// Create logs directory
$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Enhanced logging function
function logMessage($message, $level = 'INFO') {
    global $logDir;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message";
    
    // Write to main log file
    file_put_contents($logDir . '/auto_checkout.log', $logMessage . "\n", FILE_APPEND | LOCK_EX);
    
    // Write to daily log
    $dailyLogFile = $logDir . '/auto_checkout_' . date('Y-m-d') . '.log';
    file_put_contents($dailyLogFile, $logMessage . "\n", FILE_APPEND | LOCK_EX);
    
    // Output for manual runs
    if (isset($_GET['manual_run']) || isset($_GET['test']) || php_sapi_name() !== 'cli') {
        echo $logMessage . "<br>\n";
    }
}

// Check if this is a manual run
$isManualRun = isset($_GET['manual_run']) || isset($_GET['test']) || php_sapi_name() !== 'cli';

if ($isManualRun) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>Auto Checkout Execution</title>";
    echo "<style>body{font-family:Arial;margin:20px;line-height:1.6;} .success{color:green;} .error{color:red;} .warning{color:orange;} .info{color:blue;}</style>";
    echo "</head><body>";
    echo "<h2>🕙 Daily 10:00 AM Auto Checkout Execution</h2>";
    echo "<p><strong>Current Time:</strong> " . date('H:i:s') . " (Asia/Kolkata)</p>";
    echo "<p><strong>Current Date:</strong> " . date('Y-m-d') . "</p>";
    echo "<p><strong>Execution Mode:</strong> " . ($isManualRun ? 'MANUAL TEST' : 'AUTOMATIC CRON') . "</p>";
    logMessage("MANUAL AUTO CHECKOUT TEST STARTED", 'TEST');
} else {
    logMessage("DAILY 10:00 AM AUTO CHECKOUT STARTED", 'CRON');
}

logMessage("Execution mode: " . ($isManualRun ? 'MANUAL TEST' : 'AUTOMATIC CRON'));
logMessage("Target time: 10:00 AM daily");
logMessage("Current time: " . date('H:i:s'));
logMessage("Current date: " . date('Y-m-d'));

// FIXED: Database connection with enhanced error handling
try {
    $host = 'localhost';
    $dbname = 'u261459251_patel';
    $username = 'u261459251_levagt';
    $password = 'GtPatelsamaj@0330';
    
    logMessage("Attempting database connection to $host/$dbname");
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 30
    ]);
    
    $pdo->exec("SET time_zone = '+05:30'");
    logMessage("Database connection successful");
    
} catch(PDOException $e) {
    $error = "Database connection failed: " . $e->getMessage();
    logMessage($error, 'ERROR');
    
    if ($isManualRun) {
        echo "<p class='error'>$error</p>";
        echo "</body></html>";
    }
    exit(1);
}

// FIXED: Verify required tables exist
try {
    $requiredTables = ['bookings', 'resources', 'auto_checkout_logs', 'system_settings'];
    foreach ($requiredTables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        logMessage("Table '$table' verified with $count records");
    }
} catch (Exception $e) {
    $error = "Database table verification failed: " . $e->getMessage();
    logMessage($error, 'ERROR');
    
    if ($isManualRun) {
        echo "<p class='error'>$error</p>";
        echo "<p>Please run the SQL migration file to create required tables.</p>";
        echo "</body></html>";
    }
    exit(1);
}

// FIXED: Load and execute auto checkout
try {
    require_once dirname(__DIR__) . '/includes/auto_checkout.php';
    
    logMessage("AutoCheckout class loaded successfully");
    
    $autoCheckout = new AutoCheckout($pdo);
    
    // FIXED: Execute the daily checkout with proper timing
    $result = $autoCheckout->executeDailyCheckout();
    
    // Enhanced result logging
    $logLevel = $result['status'] === 'error' ? 'ERROR' : 'INFO';
    logMessage("Auto Checkout Execution Result: " . json_encode($result), $logLevel);
    
    // Output result for manual runs
    if ($isManualRun) {
        echo "<h3>Execution Results:</h3>";
        echo "<div style='background: #f8f9fa; padding: 1rem; border-radius: 8px; border: 1px solid #ddd;'>";
        echo "<pre>" . json_encode($result, JSON_PRETTY_PRINT) . "</pre>";
        echo "</div>";
        
        if ($result['status'] === 'completed') {
            echo "<p class='success'>✅ Auto checkout completed successfully!</p>";
            echo "<p>Checked out: " . ($result['checked_out'] ?? 0) . " bookings</p>";
            echo "<p>Failed: " . ($result['failed'] ?? 0) . " bookings</p>";
            
            if (isset($result['details']['successful']) && !empty($result['details']['successful'])) {
                echo "<h4>Successfully Checked Out:</h4>";
                echo "<ul>";
                foreach ($result['details']['successful'] as $booking) {
                    $resourceName = $booking['custom_name'] ?: $booking['display_name'];
                    echo "<li>{$resourceName}: {$booking['client_name']}</li>";
                }
                echo "</ul>";
            }
            
        } elseif ($result['status'] === 'no_bookings') {
            echo "<p class='warning'>⚠️ No active bookings found for checkout</p>";
        } elseif ($result['status'] === 'not_time') {
            echo "<p class='info'>ℹ️ Not time for auto checkout yet</p>";
            echo "<p>Current: " . ($result['current_time'] ?? date('H:i')) . ", Target: 10:00-10:05 AM</p>";
        } elseif ($result['status'] === 'already_run') {
            echo "<p class='info'>ℹ️ Auto checkout already ran today</p>";
        } else {
            echo "<p class='error'>❌ Auto checkout failed: " . ($result['message'] ?? 'Unknown error') . "</p>";
        }
        
        echo "<div style='margin-top: 2rem;'>";
        echo "<a href='../admin/auto_checkout_logs.php' style='background:#007bff; color:white; padding:10px 20px; text-decoration:none; border-radius:5px; margin-right:10px;'>📋 View Logs</a>";
        echo "<a href='../owner/settings.php' style='background:#28a745; color:white; padding:10px 20px; text-decoration:none; border-radius:5px; margin-right:10px;'>⚙️ Settings</a>";
        echo "<a href='../grid.php' style='background:#6c757d; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>🏠 Dashboard</a>";
        echo "</div>";
        echo "</body></html>";
    } else {
        // Command line output
        echo "Auto checkout executed: " . $result['status'] . "\n";
        if (isset($result['checked_out'])) {
            echo "Checked out: " . $result['checked_out'] . " bookings\n";
        }
        if (isset($result['failed'])) {
            echo "Failed: " . $result['failed'] . " bookings\n";
        }
        if (isset($result['message'])) {
            echo "Message: " . $result['message'] . "\n";
        }
    }
    
    // Exit with appropriate code
    exit($result['status'] === 'error' ? 1 : 0);
    
} catch (Exception $e) {
    $errorMessage = "Auto Checkout Critical Error: " . $e->getMessage();
    logMessage($errorMessage, 'CRITICAL');
    logMessage("Stack trace: " . $e->getTraceAsString(), 'DEBUG');
    
    if ($isManualRun) {
        echo "<p class='error'>Critical Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<div style='background: #f8f9fa; padding: 1rem; border-radius: 8px; border: 1px solid #ddd;'>";
        echo "<h4>Stack Trace:</h4>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        echo "</div>";
        echo "<p><a href='../owner/settings.php'>← Back to Owner Settings</a></p>";
        echo "</body></html>";
    } else {
        echo "Critical Error: " . $e->getMessage() . "\n";
    }
    
    exit(1);
}
?>