<?php

declare(strict_types=1);

/**
 * AdsDash Automated Email Retry Scheduler Runner
 * CLI Job ONLY - Exit Codes:
 * 0 = Successful execution
 * 1 = Unexpected job failure / exception
 * 2 = Concurrency lock held (another job running)
 * 3 = Invalid/non-CLI execution attempt
 */

// 1. CLI Security Guard
if (php_sapi_name() !== 'cli' && empty($_SERVER['argv'])) {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain');
    }
    echo "Access Denied: CLI execution only.\n";
    exit(3);
}

$startTime = microtime(true);
$startFormatted = date('Y-m-d H:i:s');

// 2. Setup Storage Directories
$storageDir = __DIR__ . '/../storage';
$lockDir = $storageDir . '/locks';
$logDir = $storageDir . '/logs';

if (!file_exists($lockDir)) {
    @mkdir($lockDir, 0777, true);
}
if (!file_exists($logDir)) {
    @mkdir($logDir, 0777, true);
}

$lockFile = $lockDir . '/email-retry.lock';
$logFile = $logDir . '/email-retry.log';

// Logger helper
$writeLog = function (string $message) use ($logFile) {
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
};

// 3. Concurrency Lock Protection
$lockFp = @fopen($lockFile, 'c+');
if (!$lockFp || !@flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "Email retry job already running. Exiting.\n";
    $writeLog("LOCK CONFLICT: Another retry job is currently executing. Aborted.");
    if ($lockFp) {
        @fclose($lockFp);
    }
    exit(2);
}

$exitCode = 0;

try {
    // 4. Load Application Dependencies
    $vendorAutoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($vendorAutoload)) {
        require_once $vendorAutoload;
    }

    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/email/EmailRetryService.php';

    $writeLog("JOB STARTED: Initializing retry runner.");

    $retryService = new EmailRetryService($pdo);

    // 5. Batch Limit & Execution Setup
    $batchSize = 20;
    $maxDurationSeconds = 180; // 3 minute timeout guard

    $pending = $retryService->getPendingRetries($batchSize);
    $eligibleCount = count($pending);
    $processedCount = 0;
    $sentCount = 0;
    $failedCount = 0;
    $skippedCount = 0;

    foreach ($pending as $log) {
        // Timeout guard check
        if ((microtime(true) - $startTime) >= $maxDurationSeconds) {
            $skippedCount += ($eligibleCount - $processedCount);
            $writeLog("TIMEOUT GUARD: Reached maximum execution time limit ({$maxDurationSeconds}s). Stopping further batch processing.");
            break;
        }

        $logId = (int) $log['id'];
        $res = $retryService->retryEmail($logId, false);
        $processedCount++;

        if (!empty($res['success'])) {
            $sentCount++;
        } else {
            $failedCount++;
        }
    }

    $endTime = microtime(true);
    $endFormatted = date('Y-m-d H:i:s');
    $duration = round($endTime - $startTime, 2);

    // 6. Structured Summary Output
    echo "========================================\n";
    echo "ADSDASH EMAIL RETRY JOB\n";
    echo "========================================\n\n";
    echo "Started: {$startFormatted}\n\n";
    echo "Eligible emails: {$eligibleCount}\n";
    echo "Processed: {$processedCount}\n";
    echo "Sent: {$sentCount}\n";
    echo "Failed: {$failedCount}\n";
    echo "Skipped: {$skippedCount}\n\n";
    echo "Finished: {$endFormatted}\n";
    echo "Duration: {$duration} seconds\n\n";
    echo "========================================\n";
    echo "JOB COMPLETED\n";
    echo "========================================\n";

    $writeLog("JOB COMPLETED: Processed {$processedCount}/{$eligibleCount} emails. Sent: {$sentCount}, Failed: {$failedCount}, Skipped: {$skippedCount}. Duration: {$duration}s.");

} catch (Throwable $e) {
    $exitCode = 1;
    $errorMsg = $e->getMessage();
    echo "\n[JOB ERROR] An unexpected exception occurred during retry runner execution.\n";
    $writeLog("JOB EXCEPTION: {$errorMsg}");
} finally {
    // 7. Lock Release Guarantee
    if ($lockFp) {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }
}

exit($exitCode);
