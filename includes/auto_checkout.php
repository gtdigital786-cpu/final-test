<?php
/**
 * Enhanced Auto Checkout System for L.P.S.T Hotel Booking System
 * Fixed to work properly with manual testing and daily automatic checkout
 * No automatic payment calculation - admin marks payments manually
 */

class AutoCheckout {
    private $pdo;
    private $timezone;
    private $debugMode;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->timezone = 'Asia/Kolkata';
        date_default_timezone_set($this->timezone);
        $this->debugMode = true; // Always enable debug for troubleshooting
    }
    
    /**
     * Execute daily auto checkout - works for both manual and automatic runs
     */
    public function executeDailyCheckout() {
        $this->log("Starting auto checkout process...");
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
            $checkoutTime = $settings['auto_checkout_time'];
            $today = date('Y-m-d');
            
            // Check if this is a manual run or testing mode
            $isManualRun = $this->isManualRun();
            $testingMode = $settings['testing_mode_enabled'];
            $this->log("Manual run: " . ($isManualRun ? 'YES' : 'NO'));
            $this->log("Testing mode: " . ($testingMode ? 'YES' : 'NO'));
            $this->log("Current time: $currentTime, Checkout time: $checkoutTime");
            
            // Enhanced time checking - more precise for 10:00 AM execution
            if (!$isManualRun && !$testingMode) {
                // Check if it's exactly 10:00 AM or within 5 minutes
                $scheduledMinutes = $this->timeToMinutes($checkoutTime);
                $currentMinutes = $this->timeToMinutes($currentTime);
                $gracePeriod = 5; // 5 minutes grace period for precise execution
                
                $this->log("Scheduled minutes: $scheduledMinutes, Current minutes: $currentMinutes, Difference: " . abs($currentMinutes - $scheduledMinutes));
                
                if (abs($currentMinutes - $scheduledMinutes) > $gracePeriod) {
                    $this->log("Not time for auto checkout yet. Current: $currentTime, Required: $checkoutTime", 'INFO');
                    return [
                        'status' => 'not_time',
                        'message' => "Not time for auto checkout. Current: $currentTime, Scheduled: $checkoutTime",
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                }
                
                // Check if already ran today (only for automatic runs)
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
                $this->updateLastRunTime(); // Update even if no bookings
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
            
            // Update last run time
            $this->updateLastRunTime();
            
            // Log system activity
            $this->logSystemActivity(count($checkedOutBookings), count($failedBookings));
            
            $this->log("Auto checkout completed: " . count($checkedOutBookings) . " successful, " . count($failedBookings) . " failed");
            
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
            $this->log("Auto checkout error: " . $e->getMessage(), 'ERROR');
            return [
                'status' => 'error', 
                'message' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
    
    /**
     * Get bookings that need to be checked out
     */
    private function getBookingsForCheckout() {
        $today = date('Y-m-d');
        
        // Get all active bookings that need checkout - improved query for 10:00 AM execution
        $stmt = $this->pdo->prepare("
            SELECT b.*, r.display_name, r.custom_name, r.type
            FROM bookings b 
            JOIN resources r ON b.resource_id = r.id 
            WHERE b.status IN ('BOOKED', 'PENDING')
            AND COALESCE(b.auto_checkout_processed, 0) = 0
            AND DATE(b.check_in) <= CURDATE()
            ORDER BY b.check_in ASC
        ");
        $stmt->execute();
        
        $bookings = $stmt->fetchAll();
        $this->log("Query found " . count($bookings) . " active bookings for 10:00 AM checkout");
        
        return $bookings;
    }
    
    /**
     * Checkout a specific booking - NO AUTOMATIC PAYMENT CALCULATION
     */
    private function checkoutBooking($booking) {
        try {
            $this->pdo->beginTransaction();
            
            $checkOutTime = date('Y-m-d H:i:s');
            $checkInTime = $booking['actual_check_in'] ?: $booking['check_in'];
            
            // Calculate duration for logging only
            $start = new DateTime($checkInTime);
            $end = new DateTime($checkOutTime);
            $diff = $start->diff($end);
            $durationMinutes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
            $hours = max(1, ceil($durationMinutes / 60)); // Minimum 1 hour
            
            $this->log("Checkout calculation - Duration: {$hours}h - NO AUTOMATIC PAYMENT");
            
            // Update booking - NO PAYMENT CALCULATION
            $stmt = $this->pdo->prepare("
                UPDATE bookings 
                SET status = 'COMPLETED',
                    actual_check_out = ?,
                    duration_minutes = ?,
                    auto_checkout_processed = 1,
                    actual_checkout_date = CURDATE(),
                    actual_checkout_time = CURTIME(),
                    payment_notes = CONCAT(COALESCE(payment_notes, ''), ' - Auto checkout at 10:00 AM')
                WHERE id = ?
            ");
            $stmt->execute([$checkOutTime, $durationMinutes, $booking['id']]);
            
            // Log the checkout - NO PAYMENT RECORD
            $resourceName = $booking['custom_name'] ?: $booking['display_name'];
            $notes = "Daily 10:00 AM auto checkout - Duration: {$hours}h - Admin must mark payment manually";
            
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
                '10:00:00', // Always log as 10:00 AM checkout
                $notes
            ]);
            
            // Send SMS if available
            $this->sendCheckoutSMS($booking);
            
            $this->pdo->commit();
            $this->log("Successfully checked out booking ID: " . $booking['id'] . " at 10:00 AM - Admin must mark payment");
            
            return [
                'success' => true, 
                'duration' => $hours,
                'resource_name' => $resourceName,
                'payment_note' => 'Admin must mark payment manually after 10:00 AM auto checkout'
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
               (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') ||
               (php_sapi_name() !== 'cli') ||
               isset($_GET['force']);
    }
    
    /**
     * Convert time string to minutes
     */
    private function timeToMinutes($time) {
        list($hours, $minutes) = explode(':', $time);
        return ($hours * 60) + $minutes;
    }
    
    /**
     * Get system settings with defaults
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
                'checkout_grace_minutes' => intval($settings['checkout_grace_minutes'] ?? 30),
                'testing_mode_enabled' => ($settings['testing_mode_enabled'] ?? '0') === '1',
                'debug_mode' => ($settings['debug_mode'] ?? '1') === '1'
            ];
        } catch (Exception $e) {
            $this->log("Error loading settings, using defaults: " . $e->getMessage(), 'WARNING');
            // Return defaults if table doesn't exist
            return [
                'auto_checkout_enabled' => true,
                'auto_checkout_time' => '10:00',
                'timezone' => 'Asia/Kolkata',
                'last_auto_checkout_run' => '',
                'checkout_grace_minutes' => 30,
                'testing_mode_enabled' => false,
                'debug_mode' => true
            ];
        }
    }
    
    /**
     * Update last run time
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
                date('H:i:s'),
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
            $description = "Auto checkout completed: {$successful} successful, {$failed} failed - No automatic payment calculation";
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
     * Get checkout statistics
     */
    public function getCheckoutStats() {
        try {
            $today = date('Y-m-d');
            $weekStart = date('Y-m-d', strtotime('-7 days'));
            
            // Today's stats
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count
                FROM auto_checkout_logs acl
                WHERE DATE(acl.created_at) = ? AND acl.status = 'success'
            ");
            $stmt->execute([$today]);
            $todayStats = $stmt->fetch();
            
            // Week's stats
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count
                FROM auto_checkout_logs acl
                WHERE DATE(acl.created_at) >= ? AND acl.status = 'success'
            ");
            $stmt->execute([$weekStart]);
            $weekStats = $stmt->fetch();
            
            return [
                'today' => [
                    'count' => $todayStats['count'] ?: 0
                ],
                'week' => [
                    'count' => $weekStats['count'] ?: 0
                ]
            ];
        } catch (Exception $e) {
            $this->log("Error getting stats: " . $e->getMessage(), 'ERROR');
            return [
                'today' => ['count' => 0],
                'week' => ['count' => 0]
            ];
        }
    }
    
    /**
     * Test auto checkout (for manual testing)
     */
    public function testAutoCheckout() {
        $this->log("MANUAL TEST STARTED", 'TEST');
        return $this->executeDailyCheckout();
    }
    
    /**
     * Force checkout all active bookings (for emergency use)
     */
    public function forceCheckoutAll() {
        $this->log("FORCE CHECKOUT ALL STARTED", 'FORCE');
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
        
        // Create logs directory if it doesn't exist
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Write to log file
        file_put_contents($logDir . '/auto_checkout.log', $logMessage . "\n", FILE_APPEND | LOCK_EX);
        
        // Also write to daily log
        $dailyLogFile = $logDir . '/auto_checkout_' . date('Y-m-d') . '.log';
        file_put_contents($dailyLogFile, $logMessage . "\n", FILE_APPEND | LOCK_EX);
        
        // Output for manual runs or debug mode
        if ($this->debugMode && (isset($_GET['manual_run']) || isset($_GET['test']) || php_sapi_name() !== 'cli')) {
            echo $logMessage . "<br>\n";
        }
    }
}
?>