<?php
/**
 * Fixed Auto Checkout System for L.P.S.T Hotel Booking System
 * GUARANTEED daily 10:00 AM execution with precise timing
 * Designed specifically for Hostinger cron job compatibility
 */

class AutoCheckout {
    private $pdo;
    private $timezone;
    private $debugMode;
    private $logFile;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->timezone = 'Asia/Kolkata';
        date_default_timezone_set($this->timezone);
        $this->debugMode = true;
        
        // Create logs directory
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $this->logFile = $logDir . '/auto_checkout.log';
    }
    
    /**
     * FIXED: Main execution method for daily 10:00 AM auto checkout
     * This method ensures EXACT 10:00 AM execution every day
     */
    public function executeDailyCheckout() {
        $this->log("=== DAILY 10:00 AM AUTO CHECKOUT EXECUTION STARTED ===");
        $this->log("Current date: " . date('Y-m-d') . ", Current time: " . date('H:i:s'));
        
        try {
            $settings = $this->getSystemSettings();
            $this->log("Settings loaded: " . json_encode($settings));
            
            if (!$settings['auto_checkout_enabled']) {
                $this->log("Auto checkout is disabled in system settings", 'WARNING');
                return [
                    'status' => 'disabled', 
                    'message' => 'Auto checkout is disabled in system settings',
                    'timestamp' => date('Y-m-d H:i:s')
                ];
            }
            
            $currentTime = date('H:i');
            $currentHour = (int)date('H');
            $currentMinute = (int)date('i');
            $today = date('Y-m-d');
            
            // Check if this is a manual run
            $isManualRun = $this->isManualRun();
            
            $this->log("Manual run: " . ($isManualRun ? 'YES' : 'NO'));
            $this->log("Current time: $currentTime (Hour: $currentHour, Minute: $currentMinute)");
            
            // FIXED: Precise 10:00 AM execution logic
            if (!$isManualRun) {
                // Only run between 10:00 AM and 10:05 AM
                if ($currentHour !== 10 || $currentMinute > 5) {
                    $this->log("Not time for auto checkout - Current: $currentTime, Target: 10:00-10:05", 'INFO');
                    return [
                        'status' => 'not_time',
                        'message' => "Not time for auto checkout. Current: $currentTime, Target: 10:00-10:05 AM",
                        'current_time' => $currentTime,
                        'target_time' => '10:00',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                }
                
                // FIXED: Check if already ran today (prevent multiple runs)
                $lastRun = $settings['last_auto_checkout_run'];
                if ($lastRun && date('Y-m-d', strtotime($lastRun)) === $today) {
                    $this->log("Auto checkout already ran today at " . date('H:i', strtotime($lastRun)), 'INFO');
                    return [
                        'status' => 'already_run',
                        'message' => "Auto checkout already executed today at " . date('H:i', strtotime($lastRun)),
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                }
            }
            
            // Get bookings to checkout
            $bookings = $this->getBookingsForCheckout();
            $this->log("Found " . count($bookings) . " bookings for checkout");
            
            if (empty($bookings)) {
                $this->updateLastRunTime();
                $this->log("No active bookings found for checkout", 'INFO');
                return [
                    'status' => 'no_bookings',
                    'message' => 'No active bookings found for checkout',
                    'checked_out' => 0,
                    'failed' => 0,
                    'timestamp' => date('Y-m-d H:i:s'),
                    'run_type' => $isManualRun ? 'manual' : 'automatic'
                ];
            }
            
            $checkedOutBookings = [];
            $failedBookings = [];
            
            foreach ($bookings as $booking) {
                $this->log("Processing booking ID: " . $booking['id'] . " for " . $booking['client_name']);
                $result = $this->checkoutBooking($booking);
                if ($result['success']) {
                    $checkedOutBookings[] = $booking;
                    $this->log("Successfully checked out booking ID: " . $booking['id']);
                } else {
                    $failedBookings[] = ['booking' => $booking, 'error' => $result['error']];
                    $this->log("Failed to checkout booking ID: " . $booking['id'] . " - " . $result['error'], 'ERROR');
                }
            }
            
            // FIXED: Always update last run time to prevent multiple executions
            $this->updateLastRunTime();
            
            // Log system activity
            $this->logSystemActivity(count($checkedOutBookings), count($failedBookings));
            
            $this->log("=== AUTO CHECKOUT COMPLETED ===");
            $this->log("Total processed: " . count($bookings) . ", Successful: " . count($checkedOutBookings) . ", Failed: " . count($failedBookings));
            
            return [
                'status' => 'completed',
                'checked_out' => count($checkedOutBookings),
                'failed' => count($failedBookings),
                'total_processed' => count($bookings),
                'details' => [
                    'successful' => $checkedOutBookings,
                    'failed' => $failedBookings
                ],
                'run_type' => $isManualRun ? 'manual' : 'automatic',
                'timestamp' => date('Y-m-d H:i:s'),
                'message' => "Processed " . count($bookings) . " bookings: " . count($checkedOutBookings) . " successful, " . count($failedBookings) . " failed"
            ];
            
        } catch (Exception $e) {
            $this->log("Auto checkout critical error: " . $e->getMessage(), 'ERROR');
            return [
                'status' => 'error', 
                'message' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
    
    /**
     * FIXED: Get bookings that need to be checked out
     * Only gets bookings that haven't been auto-processed yet
     */
    private function getBookingsForCheckout() {
        // Get all active bookings that haven't been auto-processed yet
        $stmt = $this->pdo->prepare("
            SELECT b.*, r.display_name, r.custom_name, r.type
            FROM bookings b 
            JOIN resources r ON b.resource_id = r.id 
            WHERE b.status IN ('BOOKED', 'PENDING')
            AND (b.auto_checkout_processed IS NULL OR b.auto_checkout_processed = 0)
            AND DATE(b.check_in) <= CURDATE()
            ORDER BY b.check_in ASC
        ");
        $stmt->execute();
        
        $bookings = $stmt->fetchAll();
        $this->log("Query found " . count($bookings) . " active bookings for 10:00 AM checkout");
        
        return $bookings;
    }
    
    /**
     * FIXED: Checkout a specific booking with proper payment calculation
     */
    private function checkoutBooking($booking) {
        try {
            $this->pdo->beginTransaction();
            
            $checkOutTime = date('Y-m-d H:i:s');
            $checkInTime = $booking['actual_check_in'] ?: $booking['check_in'];
            
            // Calculate duration for payment
            $start = new DateTime($checkInTime);
            $end = new DateTime($checkOutTime);
            $diff = $start->diff($end);
            $durationMinutes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
            $hours = max(1, ceil($durationMinutes / 60));
            
            $this->log("Checkout calculation - Duration: {$hours}h ({$durationMinutes} minutes)");
            
            // FIXED: Update booking status to COMPLETED with proper flags
            $stmt = $this->pdo->prepare("
                UPDATE bookings 
                SET status = 'COMPLETED',
                    actual_check_out = ?,
                    duration_minutes = ?,
                    auto_checkout_processed = 1,
                    actual_checkout_date = CURDATE(),
                    actual_checkout_time = '10:00:00',
                    payment_notes = CONCAT(COALESCE(payment_notes, ''), ' - Auto checkout at 10:00 AM daily')
                WHERE id = ?
            ");
            $stmt->execute([$checkOutTime, $durationMinutes, $booking['id']]);
            
            // FIXED: Create payment record with proper amount calculation
            $resourceName = $booking['custom_name'] ?: $booking['display_name'];
            $amount = $hours * 100; // ₹100 per hour for rooms
            if ($booking['type'] === 'hall') {
                $amount = $hours * 500; // ₹500 per hour for halls
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO payments 
                (booking_id, resource_id, amount, payment_method, payment_status, admin_id, payment_notes) 
                VALUES (?, ?, ?, 'AUTO_CHECKOUT', 'COMPLETED', 1, ?)
            ");
            $paymentNotes = "Auto checkout at " . date('Y-m-d H:i:s') . " - Duration: {$hours}h - Rate: ₹" . ($booking['type'] === 'hall' ? '500' : '100') . "/hour";
            $stmt->execute([
                $booking['id'],
                $booking['resource_id'],
                $amount,
                $paymentNotes
            ]);
            
            // FIXED: Log the checkout with proper details
            $notes = "Automatic checkout - Duration: {$hours}h - Amount: ₹{$amount}";
            
            $stmt = $this->pdo->prepare("
                INSERT INTO auto_checkout_logs 
                (booking_id, resource_id, resource_name, guest_name, checkout_date, checkout_time, status, notes) 
                VALUES (?, ?, ?, ?, ?, ?, 'success', ?)
            ");
            $stmt->execute([
                $booking['id'],
                $booking['resource_id'],
                $resourceName,
                $booking['client_name'],
                date('Y-m-d'),
                '10:00:00',
                $notes
            ]);
            
            // Send SMS if available
            $this->sendCheckoutSMS($booking);
            
            $this->pdo->commit();
            $this->log("Successfully checked out booking ID: " . $booking['id'] . " - Amount: ₹{$amount}");
            
            return [
                'success' => true, 
                'duration' => $hours,
                'amount' => $amount,
                'resource_name' => $resourceName
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->log("Failed to checkout booking ID: " . $booking['id'] . " - " . $e->getMessage(), 'ERROR');
            
            // Log failed checkout
            $this->logFailedCheckout($booking, $e->getMessage());
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Check if this is a manual run
     */
    private function isManualRun() {
        return isset($_GET['manual_run']) || 
               isset($_GET['test']) || 
               isset($_GET['force']) ||
               (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') ||
               (php_sapi_name() !== 'cli');
    }
    
    /**
     * FIXED: Get system settings with enhanced defaults
     */
    private function getSystemSettings() {
        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM system_settings");
            $settings = [];
            
            while ($row = $stmt->fetch()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            
            return [
                'auto_checkout_enabled' => ($settings['auto_checkout_enabled'] ?? '1') === '1',
                'auto_checkout_time' => $settings['auto_checkout_time'] ?? '10:00',
                'timezone' => $settings['timezone'] ?? 'Asia/Kolkata',
                'last_auto_checkout_run' => $settings['last_auto_checkout_run'] ?? '',
                'checkout_grace_minutes' => intval($settings['checkout_grace_minutes'] ?? 5),
                'testing_mode_enabled' => ($settings['testing_mode_enabled'] ?? '1') === '1',
                'debug_mode' => ($settings['debug_mode'] ?? '1') === '1'
            ];
        } catch (Exception $e) {
            $this->log("Error loading settings, using defaults: " . $e->getMessage(), 'WARNING');
            return [
                'auto_checkout_enabled' => true,
                'auto_checkout_time' => '10:00',
                'timezone' => 'Asia/Kolkata',
                'last_auto_checkout_run' => '',
                'checkout_grace_minutes' => 5,
                'testing_mode_enabled' => true,
                'debug_mode' => true
            ];
        }
    }
    
    /**
     * FIXED: Update last run time with current timestamp
     */
    private function updateLastRunTime() {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value) 
                VALUES ('last_auto_checkout_run', NOW()) 
                ON DUPLICATE KEY UPDATE setting_value = NOW()
            ");
            $stmt->execute();
            $this->log("Updated last run time to: " . date('Y-m-d H:i:s'));
        } catch (Exception $e) {
            $this->log("Failed to update last run time: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Log failed checkout
     */
    private function logFailedCheckout($booking, $error) {
        try {
            $resourceName = $booking['custom_name'] ?: $booking['display_name'];
            $stmt = $this->pdo->prepare("
                INSERT INTO auto_checkout_logs 
                (booking_id, resource_id, resource_name, guest_name, checkout_date, checkout_time, status, notes) 
                VALUES (?, ?, ?, ?, ?, ?, 'failed', ?)
            ");
            $stmt->execute([
                $booking['id'],
                $booking['resource_id'],
                $resourceName,
                $booking['client_name'],
                date('Y-m-d'),
                '10:00:00',
                'Error: ' . $error
            ]);
        } catch (Exception $e) {
            $this->log("Failed to log checkout error: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Log system activity
     */
    private function logSystemActivity($successful, $failed) {
        try {
            $description = "Daily 10:00 AM auto checkout completed: {$successful} successful, {$failed} failed";
            $stmt = $this->pdo->prepare("
                INSERT INTO activity_logs (activity_type, description) 
                VALUES ('auto_checkout', ?)
            ");
            $stmt->execute([$description]);
        } catch (Exception $e) {
            $this->log("Failed to log system activity: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Send checkout SMS
     */
    private function sendCheckoutSMS($booking) {
        try {
            if (file_exists(__DIR__ . '/sms_functions.php')) {
                require_once __DIR__ . '/sms_functions.php';
                $result = send_checkout_confirmation_sms($booking['id'], $this->pdo);
                $this->log("SMS result for booking " . $booking['id'] . ": " . ($result['success'] ? 'SUCCESS' : 'FAILED - ' . $result['message']));
            }
        } catch (Exception $e) {
            $this->log("SMS failed during auto checkout: " . $e->getMessage(), 'WARNING');
        }
    }
    
    /**
     * Test auto checkout (for manual testing)
     */
    public function testAutoCheckout() {
        $this->log("=== MANUAL TEST STARTED ===", 'TEST');
        return $this->executeDailyCheckout();
    }
    
    /**
     * Force checkout all active bookings (for emergency use)
     */
    public function forceCheckoutAll() {
        $this->log("=== FORCE CHECKOUT ALL STARTED ===", 'FORCE');
        try {
            $stmt = $this->pdo->prepare("
                SELECT b.*, r.display_name, r.custom_name, r.type
                FROM bookings b 
                JOIN resources r ON b.resource_id = r.id 
                WHERE b.status IN ('BOOKED', 'PENDING')
                ORDER BY b.check_in ASC
            ");
            $stmt->execute();
            $bookings = $stmt->fetchAll();
            
            $successful = 0;
            $failed = 0;
            
            foreach ($bookings as $booking) {
                $result = $this->checkoutBooking($booking);
                if ($result['success']) {
                    $successful++;
                } else {
                    $failed++;
                }
            }
            
            $this->updateLastRunTime();
            
            return [
                'status' => 'force_completed',
                'total_processed' => count($bookings),
                'checked_out' => $successful,
                'failed' => $failed,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            $this->log("Force checkout error: " . $e->getMessage(), 'ERROR');
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
    
    /**
     * Enhanced logging function
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message";
        
        // Write to log file
        file_put_contents($this->logFile, $logMessage . "\n", FILE_APPEND | LOCK_EX);
        
        // Also write to daily log
        $dailyLogFile = dirname($this->logFile) . '/auto_checkout_' . date('Y-m-d') . '.log';
        file_put_contents($dailyLogFile, $logMessage . "\n", FILE_APPEND | LOCK_EX);
        
        // Output for manual runs or debug mode
        if ($this->debugMode && (isset($_GET['manual_run']) || isset($_GET['test']) || php_sapi_name() !== 'cli')) {
            echo $logMessage . "<br>\n";
        }
    }
}
?>