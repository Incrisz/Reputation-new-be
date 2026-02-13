https://be-api.bmp.com.ng
https://be.bmp.com.ng

## Async Audit Queue Setup

1. Ensure queue tables exist (already included in default migrations):
   - `jobs`
   - `job_batches`
   - `failed_jobs`
2. Run migrations:
   - `php artisan migrate`
3. Start a worker process (example):
   - `php artisan queue:work --queue=default --tries=2 --timeout=300`
4. For production, run workers under Supervisor/systemd and scale to multiple processes for concurrency.

## Audit Completion Emails (SMTP)

When an audit finishes (success or error), the queue job sends a completion email to the audit owner.

Set these backend `.env` values:

```
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS=no-reply@yourdomain.com
MAIL_FROM_NAME="Reputation AI"
```

Then run the queue worker:

`php artisan queue:work --queue=default --tries=2 --timeout=300`
