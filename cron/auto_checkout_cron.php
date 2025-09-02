<?php
/**
 * Enhanced Auto Checkout Cron Job for L.P.S.T Hotel Booking System
 * 
 * HOSTINGER CRON JOB COMMAND:
 * 0 10 * * * /usr/bin/php /home/u261459251/domains/lpstnashik.in/public_html/cron/auto_checkout_cron.php
 */

// Set timezone first
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
    
    // Write to log file
    file_put_contents($logDir . '/auto_checkout.log', $logMessage . "\n", FILE_APPEND | LOCK_EX);
    
    // Also write to daily log
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
    header('Content-Type: text/html');
    echo "<!DOCTYPE html><html><head><title>Auto Checkout Test</title></head><body>";
    echo "<h2>Auto Checkout Cron Test</h2>";
    logMessage("MANUAL AUTO CHECKOUT TEST STARTED", 'TEST');
} else {
    logMessage("AUTOMATIC CRON AUTO CHECKOUT STARTED", 'CRON');
}

logMessage("PHP SAPI: " . php_sapi_name());
logMessage("Current working directory: " . getcwd());
logMessage("Script path: " . __FILE__);

// Database connection with enhanced error handling
try {
    $host = 'localhost';
    $dbname = 'u261459251_patel';
    $username = 'u261459251_levagt';
    $password = 'GtPatelsamaj@0330';
    
    logMessage("Attempting database connection to $host/$dbname with user $username");
    
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
        echo "<p style='color: red;'>$error</p>";
        echo "</body></html>";
    }
    exit(1);
}

// Check if required tables exist
try {
    $tables = ['bookings', 'resources', 'auto_checkout_logs', 'system_settings'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        logMessage("Table '$table' exists with $count records");
    }
} catch (Exception $e) {
    $error = "Database table check failed: " . $e->getMessage();
    logMessage($error, 'ERROR');
    
    if ($isManualRun) {
        echo "<p style='color: red;'>$error</p>";
        echo "</body></html>";
    }
    exit(1);
}

// Load and execute auto checkout
try {
    require_once dirname(__DIR__) . '/includes/auto_checkout.php';
    
    logMessage("AutoCheckout class loaded successfully");
    
    $autoCheckout = new AutoCheckout($pdo);
    $result = $autoCheckout->executeDailyCheckout();
    
    // Enhanced logging
    $logLevel = $result['status'] === 'error' ? 'ERROR' : 'INFO';
    $logMessage = "Auto Checkout Result: " . json_encode($result, JSON_PRETTY_PRINT);
    logMessage($logMessage, $logLevel);
    
    // Output result
    if ($isManualRun) {
        echo "<h3>Test Results:</h3>";
        echo "<pre style='background: #f8f9fa; padding: 1rem; border-radius: 8px;'>";
        echo json_encode($result, JSON_PRETTY_PRINT);
        echo "</pre>";
        
        if ($result['status'] === 'completed') {
            echo "<p style='color: green;'>✅ Auto checkout test completed successfully!</p>";
            echo "<p>Checked out: " . ($result['checked_out'] ?? 0) . " bookings</p>";
            echo "<p>Failed: " . ($result['failed'] ?? 0) . " bookings</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ Test result: " . $result['status'] . "</p>";
            if (isset($result['message'])) {
                echo "<p>Message: " . htmlspecialchars($result['message']) . "</p>";
            }
        }
        
        echo "<p><a href='../admin/manual_checkout_test.php'>← Back to Manual Test Page</a></p>";
        echo "</body></html>";
    } else {
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
        echo "<p style='color: red;'>Critical Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre style='background: #f8f9fa; padding: 1rem; border-radius: 8px;'>";
        echo htmlspecialchars($e->getTraceAsString());
        echo "</pre>";
        echo "</body></html>";
    } else {
        echo "Critical Error: " . $e->getMessage() . "\n";
    }
    
    exit(1);
}
?>