# Deploying on Railway

This project is a server-rendered PHP application: its pages and backend run in one web service. It does not need separate frontend and backend services.

## 1. Deploy the PHP service

1. Push this project to a Git provider connected to Railway.
2. Create a Railway project and deploy the repository as a service. Set the service's **Root Directory** to the directory containing this `Dockerfile` and `index.php` if they are in a subdirectory of the repository.
3. Railway will build the included Dockerfile. The container runs Apache and PHP 8.3 with the `mysqli` extension. The image and startup script explicitly enable only Apache's `mpm_prefork` module, which is compatible with this PHP setup. PHP's upload limit is configured above the app's 5 MB resume limit.
4. Generate a public domain for the PHP service in Railway's **Settings → Networking**.

The container listens on Railway's `PORT`. Do not hard-code a port in the Railway service settings.

## 2. Add and connect MySQL

1. Add a MySQL service to the same Railway project.
2. In the PHP service's **Variables**, add references to the MySQL service variables. If the database service is named `MySQL`, use:

   ```text
   MYSQLHOST=${{MySQL.MYSQLHOST}}
   MYSQLPORT=${{MySQL.MYSQLPORT}}
   MYSQLUSER=${{MySQL.MYSQLUSER}}
   MYSQLPASSWORD=${{MySQL.MYSQLPASSWORD}}
   MYSQLDATABASE=${{MySQL.MYSQLDATABASE}}
   ```

   Replace `MySQL` with the exact database service name if you named it differently. Keep these values private; do not commit credentials.

3. A newly provisioned Railway database is empty. The repository includes [`database_schema.sql`](./database_schema.sql), which creates the 13 application tables without copying any user, job, or message data. To initialize the schema, use the MySQL command-line client with the Railway MySQL service's public TCP proxy temporarily enabled:

   ```text
   mysql -h HOST -P PORT -u USER -p DATABASE_NAME < database_schema.sql
   ```

   Replace `HOST`, `PORT`, `USER`, and `DATABASE_NAME` with the Railway MySQL service's public proxy details and credentials. Use the database name from `MYSQLDATABASE` (often `railway`). The `-p` option prompts for the password. Run the command from the directory containing `database_schema.sql`; alternatively, run the statements from that file in a MySQL client connected to the Railway database.

   This creates empty tables. If you also need your existing local accounts, jobs, or other records, export and import the database data separately. Disable public access when the import is complete. Do not put database passwords in shell history or commit a data dump.

## 3. Persist uploaded files

PHP user uploads must not be stored only in the container filesystem. Attach a Railway volume to the PHP service with mount path:

```text
/var/www/html/assets/uploads
```

On the volume's first start, the entrypoint seeds an empty volume with the project's existing upload files, then PHP writes new uploads to the persistent volume.

## 4. Verify the deployment

- Check the PHP service's deployment logs for successful Apache startup.
- Open the generated public domain and test public pages, login, and an upload.
- If the app cannot connect to MySQL, verify that the PHP service has all five `MYSQL*` references and that the database service is running.
- Back up the MySQL service regularly. A volume protects uploaded files across deploys, but is not a substitute for backups.

Local development remains compatible with the existing `localhost` / `root` / `job_application` defaults. In Railway, the connection uses the `MYSQL*` variables above (or the equivalent `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, and `DB_NAME` variables).
