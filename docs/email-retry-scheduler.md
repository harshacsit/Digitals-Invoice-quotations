# AdsDash Email Retry Scheduler & Job Safety Documentation

This document describes the automated email retry scheduler, concurrency locking, security protections, and setup instructions for Windows Task Scheduler and Linux Cron.

---

## 1. Architecture Overview

The AdsDash Email Delivery System includes an automated retry runner that periodically inspects `email_logs` for failed email dispatches and retries them according to the configured exponential backoff retry policy.

```text
Scheduler (Cron / Task Scheduler)
  └── php scratch/run_email_retries.php
       ├── 1. Verify CLI Execution (Block HTTP requests - Exit 3)
       ├── 2. Acquire Filesystem Lock (storage/locks/email-retry.lock - Exit 2 on collision)
       ├── 3. Query Pending Retries (Max 20 batch limit, respects next_retry_at)
       ├── 4. Execute EmailRetryService -> EmailDispatcher
       ├── 5. Log Job Metrics (storage/logs/email-retry.log)
       └── 6. Release Lock & Exit (Exit 0 on success, Exit 1 on error)
```

---

## 2. CLI Security & Concurrency Lock

- **CLI-Only Access**: Web requests to `run_email_retries.php` via HTTP (`http://localhost/adsdash/scratch/run_email_retries.php`) are strictly blocked (`HTTP 403 Forbidden` / Exit Code 3).
- **Concurrency Protection**: Uses non-blocking filesystem locks (`flock()`). If a retry job is already running, secondary executions exit immediately with Exit Code 2 without duplicating email dispatches.
- **Batching & Timeouts**: Processes a maximum batch size of 20 emails per execution and enforces a 180-second timeout guard.

---

## 3. Exit Codes Reference

| Exit Code | Meaning | Action Taken |
| :--- | :--- | :--- |
| `0` | **Success** | Job executed cleanly and processed pending retries. |
| `1` | **Unexpected Error** | An unhandled exception occurred; error logged to `storage/logs/email-retry.log`. |
| `2` | **Lock Conflict** | Another retry job is currently running; execution skipped safely. |
| `3` | **Access Denied** | Non-CLI (web browser) execution attempt blocked. |

---

## 4. Windows Task Scheduler Setup (Development / XAMPP)

To run the retry job automatically every 5 minutes on Windows:

1. Open **Task Scheduler** (`taskschd.msc`).
2. Click **Create Task** (not Create Basic Task).
3. Under the **General** tab:
   - **Name**: `AdsDash Email Retry Scheduler`
   - Select **Run whether user is logged on or not** (or Run only when user is logged on).
4. Under the **Triggers** tab:
   - Click **New...**
   - **Begin the task**: On a schedule (Daily, starting today)
   - Under **Advanced settings**: Check **Repeat task every**: `5 minutes` for a duration of `Indefinitely`.
5. Under the **Actions** tab:
   - Click **New...**
   - **Action**: `Start a program`
   - **Program/script**: `C:\xampp\php\php.exe`
   - **Add arguments**: `C:\Users\HP\Desktop\Digitals-Invoice-quotations\adsdash\scratch\run_email_retries.php`
   - **Start in**: `C:\Users\HP\Desktop\Digitals-Invoice-quotations\adsdash`
6. Click **OK** and enter your Windows user credentials if prompted.

---

## 5. Linux Cron Setup (Production Server)

To configure the retry scheduler on Linux production environments:

1. Open crontab editor:
   ```bash
   crontab -e
   ```
2. Add the following entry (adjusting `/var/www/html/adsdash` to your server's web root):
   ```bash
   */5 * * * * /usr/bin/php /var/www/html/adsdash/scratch/run_email_retries.php >> /var/www/html/adsdash/storage/logs/email-retry-cron.log 2>&1
   ```
3. Save and close crontab.

---

## 6. Logs & Monitoring Inspection

- **File Log**: `storage/logs/email-retry.log`
- **Database Logs**: Inspected in real time via `email-logs.html` dashboard UI or `api/email.php?action=logs`.
