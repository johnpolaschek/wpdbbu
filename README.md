# wpdbbu
Wordpress Plugin which enables database backups via a scheduler

How It Works
* Backup Jobs: Create and schedule backup jobs (daily, weekly, monthly) with configurable times and options to store backups as ZIP/TAR files on the server or email them.
* Pruning: When storing on the server, only the latest 30 daily backups or 12 weekly/monthly backups are kept.
* Backup Files Admin Page: A submenu page lists all backup files with details including the job ID, schedule type, timestamp (extracted from the filename), file creation date/time, and size. Download links now force a file download rather than displaying the file in the browser.
* WP‑Cron: Scheduled jobs run via WP‑Cron and automatically re‑schedule after execution.
Simply upload this file to a folder named wp-db-backup-scheduler in your wp-content/plugins/ directory, then activate the plugin in your WordPress admin. Enjoy your fully featured backup scheduler!


A few additional details
1. Timezone Consistency: At the top, we set the PHP default timezone based on your WordPress setting (or default to Los Angeles). This makes all date/time calculations consistent.
2. Next Run Calculation: In wp_db_compute_next_run_time(), we use the local date (via date('Y-m-d', $now)) and the backup time to compute today’s run. For weekly and monthly schedules, we adjust the day accordingly. In wp_db_schedule_backup_job(), if the computed next run is less than 30 minutes away, we bump it by one full period (daily, weekly, or monthly). We also ensure the next run is at least 5 minutes in the future.
3. Cron Callback with Transient: The callback uses a transient to avoid duplicate executions within a 5‑minute window.
4. Backup Process: The SQL dump is written to a temporary file. It is then compressed into a ZIP archive (if ZIP is chosen) and the temporary file is deleted—so only one ZIP is produced at the scheduled run.
5. Admin Pages: The backup files admin page displays file creation times using date_i18n() so that they reflect your local (Los Angeles) timezone.
