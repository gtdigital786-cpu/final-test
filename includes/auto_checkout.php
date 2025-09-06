<?php
/**
 * COMPLETELY REBUILT Auto Checkout System for L.P.S.T Hotel
 * GUARANTEED daily 10:00 AM execution with foolproof logic
 * 
 * This is a complete rewrite to ensure 100% reliability
 */

class AutoCheckout {
    private $pdo;
    private $timezone;
    private $logFile;
    private $debugMode;
    
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
        $this->logFile = $logDir . '/auto_checkout_' . date('Y-m-d') . '.log';
    }
    
    /**
     * MAIN EXECUTION METHOD - GUARANTEED 10:00 AM DAILY EXECUTION
     * This method has foolproof logic to ensure execution ONLY at 10:00 AM
     */
    public function executeDailyCheckout() {
        $startTime = microtime(true);
        $this->log("=== AUTO CHECKOUT EXECUTION STARTED ===");
        $this->log("Server time: " . date('Y-m-d H:i:s'));
        $this->log("Timezone: " . date_default_timezone_get());
        
        try {
            // Get system settings
            $settings = $this->getSystemSettings();
            $this->log("System settings loaded: " . json_encode($settings));
            
            // Check if auto checkout is enabled
            if (!$settings['enabled']) {
                $this->log("Auto checkout is DISABLED in system settings", 'WARNING');
                return $this->createResponse('disabled', 'Auto checkout is disabled in system settings');
            }
            
            $currentDate = date('Y-m-d');
            $currentTime = date('H:i');
            $currentHour = (int)date('H');
            $currentMinute = (int)date('i');
            
            $this->log("Current date: $currentDate");
            $this->log("Current time: $currentTime (Hour: $currentHour, Minute: $currentMinute)");
            
            // Check if this is a manual run
            $isManualRun = $this->isManualRun();
            $this->log("Execution type: " . ($isManualRun ? 'MANUAL' : 'AUTOMATIC'));
            
            // FOOLPROOF TIME CHECK - ONLY RUN BETWEEN 10:00-10:05 AM
            if (!$isManualRun) {
                if ($currentHour !== 10) {
                    $this->log("WRONG HOUR: Current hour is $currentHour, required hour is 10", 'SKIP');
                    return $this->createResponse('wrong_hour', "Current hour is $currentHour, auto checkout only runs at hour 10 (10:00 AM)");
                }
                
                if ($currentMinute > 5) {
                    $this->log("WRONG MINUTE: Current minute is $currentMinute, execution window is 0-5 minutes", 'SKIP');
                    return $this->createResponse('wrong_minute', "Current minute is $currentMinute, execution window is 10:00-10:05 AM");
                }
                
                // Check if already executed today
                if ($this->hasExecutedToday($currentDate)) {
                    $this->log("ALREADY EXECUTED: Auto checkout already ran today", 'SKIP');
                    return $this->createResponse('already_executed', 'Auto checkout already executed today');
                }
            }
            
            $this->log("TIME CHECK PASSED - Proceeding with auto checkout");
            
            // Get bookings to checkout
            $bookings = $this->getBookingsForCheckout();
            $this->log("Found " . count($bookings) . " bookings for checkout");
            
            if (empty($bookings)) {
                $this->recordExecution($currentDate, $currentTime, 'no_bookings', 0, 0, 0, 'No active bookings found');
                $this->log("No active bookings found for checkout");
                return $this->createResponse('no_bookings', 'No active bookings found for checkout');
            }
            
            // Process each booking
            $successful = 0;
            $failed = 0;
            $successfulBookings = [];
            $failedBookings = [];
            
            foreach ($bookings as $booking) {
                $this->log("Processing booking ID: {$booking['id']} - {$booking['client_name']} in {$booking['resource_name']}");
                
                $result = $this->processBookingCheckout($booking);
                
                if ($result['success']) {
                    $successful++;
                    $successfulBookings[] = $booking;
                    $this->log("✅ Successfully processed booking ID: {$booking['id']}");
                } else {
                    $failed++;
                    $failedBookings[] = ['booking' => $booking, 'error' => $result['error']];
                    $this->log("❌ Failed to process booking ID: {$booking['id']} - {$result['error']}", 'ERROR');
                }
            }
            
            // Record execution
            $status = ($failed === 0) ? 'success' : (($successful > 0) ? 'partial' : 'failed');
            $this->recordExecution($currentDate, $currentTime, $status, count($bookings), $successful, $failed);
            
            $executionTime = round(microtime(true) - $startTime, 2);
            $this->log("=== AUTO CHECKOUT COMPLETED ===");
            $this->log("Total processed: " . count($bookings) . ", Successful: $successful, Failed: $failed");
            $this->log("Execution time: {$executionTime} seconds");
            
            return $this->createResponse('completed', "Processed " . count($bookings) . " bookings", [
                'total_processed' => count($bookings),
                'successful' => $successful,
                'failed' => $failed,
                'execution_time' => $executionTime,
                'successful_bookings' => $successfulBookings,
                'failed_bookings' => $failedBookings
            ]);
            
        } catch (Exception $e) {
            $this->log("CRITICAL ERROR: " . $e->getMessage(), 'ERROR');
            $this->log("Stack trace: " . $e->getTraceAsString(), 'ERROR');
            
            // Record failed execution
            try {
                $this->recordExecution(date('Y-m-d'), date('H:i'), 'failed', 0, 0, 0, $e->getMessage());
            } catch (Exception $logError) {
                $this->log("Failed to record error execution: " . $logError->getMessage(), 'ERROR');
            }
            
            return $this->createResponse('error', $e->getMessage());
        }
    }
    
    /**
     * Get bookings that need to be checked out
     */
    private function getBookingsForCheckout() {
        $stmt = $this->pdo->prepare("
            SELECT 
                b.id,
                b.resource_id,
                b.client_name,
                b.client_mobile,
                b.check_in,
                b.check_out,
                b.actual_check_in,
                b.status,
                b.admin_id,
                r.display_name,
                r.custom_name,
                r.type,
                COALESCE(r.custom_name, r.display_name) as resource_name
            FROM bookings b 
            JOIN resources r ON b.resource_id = r.id 
            WHERE b.status IN ('BOOKED', 'PENDING')
            AND b.auto_checkout_processed = 0
            AND b.is_auto_checkout_eligible = 1
            AND DATE(b.check_in) <= CURDATE()
            ORDER BY b.check_in ASC
        ");
        $stmt->execute();
        
        $bookings = $stmt->fetchAll();
        $this->log("SQL Query executed - Found " . count($bookings) . " eligible bookings");
        
        return $bookings;
    }
    
    /**
     * Process individual booking checkout
     */
    private function processBookingCheckout($booking) {
        try {
            $this->pdo->beginTransaction();
            
            $checkoutDateTime = date('Y-m-d H:i:s');
            $checkoutDate = date('Y-m-d');
            $checkoutTime = '10:00:00';
            
            // Calculate duration and amount
            $checkInTime = $booking['actual_check_in'] ?: $booking['check_in'];
            $duration = $this->calculateDuration($checkInTime, $checkoutDateTime);
            $amount = $this->calculateAmount($booking['type'], $duration['hours']);
            
            $this->log("Checkout calculation - Duration: {$duration['hours']}h {$duration['minutes']}m, Amount: ₹{$amount}");
            
            // Update booking status
            $stmt = $this->pdo->prepare("
                UPDATE bookings 
                SET status = 'COMPLETED',
                    actual_check_out = ?,
                    actual_checkout_date = ?,
                    actual_checkout_time = ?,
                    auto_checkout_processed = 1,
                    duration_minutes = ?,
                    total_amount = ?,
                    payment_notes = CONCAT(
                        COALESCE(payment_notes, ''), 
                        ' - Auto checkout at 10:00 AM on ', 
                        ?, 
                        ' - Duration: ', 
                        ?, 
                        'h - Amount: ₹', 
                        ?
                    )
                WHERE id = ?
            ");
            $stmt->execute([
                $checkoutDateTime,
                $checkoutDate,
                $checkoutTime,
                $duration['total_minutes'],
                $amount,
                $checkoutDate,
                $duration['hours'],
                $amount,
                $booking['id']
            ]);
            
            // Create payment record
            $stmt = $this->pdo->prepare("
                INSERT INTO payments 
                (booking_id, resource_id, amount, payment_method, payment_status, admin_id, payment_notes) 
                VALUES (?, ?, ?, 'AUTO_CHECKOUT', 'COMPLETED', 1, ?)
            ");
            $paymentNotes = "Auto checkout on {$checkoutDate} at 10:00 AM - Duration: {$duration['hours']}h - Rate: ₹" . ($booking['type'] === 'hall' ? '500' : '100') . "/hour";
            $stmt->execute([
                $booking['id'],
                $booking['resource_id'],
                $amount,
                $paymentNotes
            ]);
            
            // Log the checkout
            $stmt = $this->pdo->prepare("
                INSERT INTO auto_checkout_logs 
                (booking_id, resource_id, resource_name, guest_name, checkout_date, checkout_time, status, notes, amount_calculated, duration_hours) 
                VALUES (?, ?, ?, ?, ?, ?, 'success', ?, ?, ?)
            ");
            $logNotes = "Auto checkout - Duration: {$duration['hours']}h {$duration['minutes']}m - Amount: ₹{$amount} - Rate: ₹" . ($booking['type'] === 'hall' ? '500' : '100') . "/hour";
            $stmt->execute([
                $booking['id'],
                $booking['resource_id'],
                $booking['resource_name'],
                $booking['client_name'],
                $checkoutDate,
                $checkoutTime,
                $logNotes,
                $amount,
                $duration['hours']
            ]);
            
            // Send SMS notification
            $this->sendCheckoutSMS($booking);
            
            $this->pdo->commit();
            $this->log("✅ Successfully checked out booking ID: {$booking['id']} - Amount: ₹{$amount}");
            
            return [
                'success' => true,
                'amount' => $amount,
                'duration' => $duration,
                'checkout_time' => $checkoutDateTime
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->log("❌ Failed to checkout booking ID: {$booking['id']} - " . $e->getMessage(), 'ERROR');
            
            // Log failed checkout
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO auto_checkout_logs 
                    (booking_id, resource_id, resource_name, guest_name, checkout_date, checkout_time, status, notes) 
                    VALUES (?, ?, ?, ?, ?, ?, 'failed', ?)
                ");
                $stmt->execute([
                    $booking['id'],
                    $booking['resource_id'],
                    $booking['resource_name'],
                    $booking['client_name'],
                    date('Y-m-d'),
                    '10:00:00',
                    'Error: ' . $e->getMessage()
                ]);
            } catch (Exception $logError) {
                $this->log("Failed to log error: " . $logError->getMessage(), 'ERROR');
            }
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Check if auto checkout has already executed today
     */
    private function hasExecutedToday($date) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM cron_execution_logs 
                WHERE execution_date = ? 
                AND execution_type = 'automatic' 
                AND execution_status IN ('success', 'no_bookings')
            ");
            $stmt->execute([$date]);
            $count = $stmt->fetchColumn();
            
            $this->log("Execution check for $date: " . ($count > 0 ? 'ALREADY RAN' : 'NOT RAN YET'));
            return $count > 0;
            
        } catch (Exception $e) {
            $this->log("Error checking execution history: " . $e->getMessage(), 'WARNING');
            return false; // If we can't check, allow execution
        }
    }
    
    /**
     * Record cron execution
     */
    private function recordExecution($date, $time, $status, $found, $successful, $failed, $errorMessage = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO cron_execution_logs 
                (execution_date, execution_time, execution_type, bookings_found, bookings_processed, bookings_successful, bookings_failed, execution_status, error_message, server_time) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                execution_time = VALUES(execution_time),
                bookings_found = VALUES(bookings_found),
                bookings_processed = VALUES(bookings_processed),
                bookings_successful = VALUES(bookings_successful),
                bookings_failed = VALUES(bookings_failed),
                execution_status = VALUES(execution_status),
                error_message = VALUES(error_message),
                server_time = VALUES(server_time)
            ");
            
            $executionType = $this->isManualRun() ? 'manual' : 'automatic';
            $stmt->execute([$date, $time, $executionType, $found, $successful + $failed, $successful, $failed, $status, $errorMessage]);
            
            // Update last run settings
            $this->updateSystemSetting('auto_checkout_last_run_date', $date);
            $this->updateSystemSetting('auto_checkout_last_run_time', $time);
            
            $this->log("Execution recorded: $status - Found: $found, Successful: $successful, Failed: $failed");
            
        } catch (Exception $e) {
            $this->log("Failed to record execution: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Calculate duration between two timestamps
     */
    private function calculateDuration($startTime, $endTime) {
        $start = new DateTime($startTime);
        $end = new DateTime($endTime);
        $diff = $start->diff($end);
        
        $totalMinutes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
        $hours = max(1, ceil($totalMinutes / 60)); // Minimum 1 hour
        
        return [
            'hours' => $hours,
            'minutes' => $diff->i,
            'total_minutes' => $totalMinutes,
            'formatted' => sprintf('%dh %dm', $hours, $diff->i)
        ];
    }
    
    /**
     * Calculate amount based on resource type and duration
     */
    private function calculateAmount($resourceType, $hours) {
        $ratePerHour = ($resourceType === 'hall') ? 500 : 100;
        return $hours * $ratePerHour;
    }
    
    /**
     * Get system settings
     */
    private function getSystemSettings() {
        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM system_settings");
            $settings = [];
            
            while ($row = $stmt->fetch()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            
            return [
                'enabled' => ($settings['auto_checkout_enabled'] ?? '1') === '1',
                'time' => $settings['auto_checkout_time'] ?? '10:00',
                'timezone' => $settings['auto_checkout_timezone'] ?? 'Asia/Kolkata',
                'last_run_date' => $settings['auto_checkout_last_run_date'] ?? '',
                'last_run_time' => $settings['auto_checkout_last_run_time'] ?? '',
                'debug_mode' => ($settings['auto_checkout_debug_mode'] ?? '1') === '1'
            ];
            
        } catch (Exception $e) {
            $this->log("Error loading settings: " . $e->getMessage(), 'WARNING');
            return [
                'enabled' => true,
                'time' => '10:00',
                'timezone' => 'Asia/Kolkata',
                'last_run_date' => '',
                'last_run_time' => '',
                'debug_mode' => true
            ];
        }
    }
    
    /**
     * Update system setting
     */
    private function updateSystemSetting($key, $value) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$key, $value]);
        } catch (Exception $e) {
            $this->log("Failed to update setting $key: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Check if this is a manual run
     */
    private function isManualRun() {
        return isset($_GET['manual_run']) || 
               isset($_GET['test']) || 
               isset($_GET['force']) ||
               (php_sapi_name() !== 'cli');
    }
    
    /**
     * Send checkout SMS
     */
    private function sendCheckoutSMS($booking) {
        try {
            if (file_exists(__DIR__ . '/sms_functions.php')) {
                require_once __DIR__ . '/sms_functions.php';
                $result = send_checkout_confirmation_sms($booking['id'], $this->pdo);
                $this->log("SMS result for booking {$booking['id']}: " . ($result['success'] ? 'SUCCESS' : 'FAILED - ' . $result['message']));
            }
        } catch (Exception $e) {
            $this->log("SMS error: " . $e->getMessage(), 'WARNING');
        }
    }
    
    /**
     * Create standardized response
     */
    private function createResponse($status, $message, $data = []) {
        return array_merge([
            'status' => $status,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s'),
            'timezone' => $this->timezone
        ], $data);
    }
    
    /**
     * Test auto checkout (for manual testing)
     */
    public function testAutoCheckout() {
        $this->log("=== MANUAL TEST EXECUTION ===", 'TEST');
        return $this->executeDailyCheckout();
    }
    
    /**
     * Force checkout all bookings (emergency use)
     */
    public function forceCheckoutAll() {
        $this->log("=== FORCE CHECKOUT ALL BOOKINGS ===", 'FORCE');
        
        try {
            $bookings = $this->getBookingsForCheckout();
            $successful = 0;
            $failed = 0;
            
            foreach ($bookings as $booking) {
                $result = $this->processBookingCheckout($booking);
                if ($result['success']) {
                    $successful++;
                } else {
                    $failed++;
                }
            }
            
            $this->recordExecution(date('Y-m-d'), date('H:i'), 'success', count($bookings), $successful, $failed, 'Force checkout executed');
            
            return $this->createResponse('force_completed', "Force checkout completed", [
                'total_processed' => count($bookings),
                'successful' => $successful,
                'failed' => $failed
            ]);
            
        } catch (Exception $e) {
            $this->log("Force checkout error: " . $e->getMessage(), 'ERROR');
            return $this->createResponse('error', $e->getMessage());
        }
    }
    
    /**
     * Enhanced logging
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message";
        
        // Write to log file
        file_put_contents($this->logFile, $logMessage . "\n", FILE_APPEND | LOCK_EX);
        
        // Output for manual runs
        if ($this->debugMode && $this->isManualRun()) {
            echo $logMessage . "<br>\n";
        }
    }
}
?>