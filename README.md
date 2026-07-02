# Tech Org Chart

PHP/SQLite org chart with an admin panel, archive/delete controls, hierarchy validation, and a public D3 chart.

## Local Run

```bash
php -S 127.0.0.1:8080 router.php
```

Then open `http://127.0.0.1:8080`.

Run the smoke checks with:

```bash
php tests/smoke.php
```

## Render Persistence

Admin edits are stored in SQLite and uploaded images. On Render, those files must live on a persistent disk. If they stay inside the deployed project directory, a deploy, restart, or rebuild can make the app appear to revert.

On Render Free, persistent disks are not available. Use an external Postgres database and remote image storage instead.

## Render Free Setup

Recommended free-friendly setup:

- Render Free web service for the PHP app.
- Supabase or Neon Postgres for the database.
- Cloudinary for uploaded personnel photos.

Set these environment variables on Render:

```bash
DATABASE_URL=postgresql://USER:PASSWORD@HOST:5432/postgres?sslmode=require
CLOUDINARY_CLOUD_NAME=your_cloud_name
CLOUDINARY_API_KEY=your_api_key
CLOUDINARY_API_SECRET=your_api_secret
CLOUDINARY_FOLDER=orgchart/personnel
```

`ORGCHART_DATABASE_URL` can be used instead of `DATABASE_URL` if the host provides another unrelated `DATABASE_URL`.

To transfer the current local SQLite data into the external database, run this once from the project folder:

```bash
DATABASE_URL='postgresql://USER:PASSWORD@HOST:5432/postgres?sslmode=require' \
php scripts/transfer-sqlite-to-database.php
```

Then create or confirm an admin user:

```bash
DATABASE_URL='postgresql://USER:PASSWORD@HOST:5432/postgres?sslmode=require' \
ORGCHART_ADMIN_EMAIL='admin@example.com' \
ORGCHART_ADMIN_PASSWORD='change-this-to-12-plus-chars' \
php scripts/migrate.php
```

After Render has those same environment variables, deploy the service. Admin edits will persist in Postgres, and new uploaded photos will persist in Cloudinary.

If existing personnel photos still use local `uploads/...` paths and you want to make them permanent too, run this once after setting both the database and Cloudinary variables:

```bash
DATABASE_URL='postgresql://USER:PASSWORD@HOST:5432/postgres?sslmode=require' \
CLOUDINARY_CLOUD_NAME='your_cloud_name' \
CLOUDINARY_API_KEY='your_api_key' \
CLOUDINARY_API_SECRET='your_api_secret' \
php scripts/migrate-uploads-to-cloudinary.php
```

## Render Paid Disk Setup

Recommended Render setup:

1. Add a persistent disk to the web service.
2. Mount it at a stable path, for example `/var/data/orgchart`.
3. Set this environment variable:

```bash
ORGCHART_STORAGE_PATH=/var/data/orgchart
```

With that single variable:

- The SQLite database is stored at `/var/data/orgchart/orgchart.sqlite`.
- Backups are stored at `/var/data/orgchart/backups`.
- Logs are stored at `/var/data/orgchart/logs`.
- New uploads are stored at `/var/data/orgchart/uploads`.
- Browser URLs stay as `/uploads/<filename>`, and `router.php` serves those files from the persistent disk.

Optional overrides:

```bash
ORGCHART_DB_PATH=/var/data/orgchart/orgchart.sqlite
ORGCHART_UPLOAD_PATH=/var/data/orgchart/uploads
APP_TIMEZONE=Pacific/Auckland
```

## Admin Data Safety

Personnel cannot be archived while they have active direct reports. Personnel cannot be deleted while any direct reports still point to them. The admin screen shows hierarchy alerts if existing data violates those rules, such as an active person reporting to an archived manager.

Before deploying, run:

```bash
php -l app/bootstrap.php
php -l app/security.php
php -l app/services.php
php -l admin/index.php
php -l router.php
php tests/smoke.php
```
