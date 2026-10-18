# Deploying to Hostinger

After the one-time setup below, **every push to `main` goes live by itself** in about two minutes:
GitHub runs the tests, builds the design files, uploads the app over SSH, then updates the database and caches.
If a test fails, nothing is uploaded.

Follow the progress in GitHub → **Actions** → **deploy**.

Never edit files on the server: the next push overwrites them. Change the code here and push.

---

## One-time setup

Below, replace `example.com` with your domain and `u123456789` with your Hostinger SSH username.

### 1. Database
hPanel → **Databases → MySQL Databases**: create a database and a user. Write down the database name, username and password.

### 2. PHP version
hPanel → **Advanced → PHP Configuration**: choose **PHP 8.3** or newer.

### 3. SSH access
hPanel → **Advanced → SSH Access** → enable it. Write down the **IP**, **port** (usually `65002`) and **username**.

### 4. A deploy key for GitHub
On your computer (Git Bash):

```bash
ssh-keygen -t ed25519 -f nepsus_deploy -N ""
```

This makes two files:
- `nepsus_deploy.pub`: in hPanel → **SSH Access → SSH keys → Add SSH key**, paste its contents.
- `nepsus_deploy`: the private key, used in the next step. Keep it secret and delete it once it's in GitHub.

### 5. GitHub secrets
GitHub repo → **Settings → Secrets and variables → Actions → New repository secret**:

| Name | Value |
|---|---|
| `SSH_HOST` | the SSH IP from step 3 |
| `SSH_PORT` | the SSH port, e.g. `65002` |
| `SSH_USER` | the SSH username, e.g. `u123456789` |
| `SSH_KEY` | the whole contents of the private `nepsus_deploy` file |
| `APP_DIR` | `domains/example.com/laravel` |

If `php -v` over SSH shows an old version, also add a **variable** (the **Variables** tab, not Secrets) named `PHP_BIN` with the full path to PHP 8.3, e.g. `/opt/alt/php83/usr/bin/php`.

### 6. First upload and the live `.env`
1. GitHub → **Actions → deploy → Run workflow**. This uploads the files; it warns that `.env` is missing, which is expected.
2. Connect over SSH (`ssh -p 65002 u123456789@IP`) and run:

```bash
cd ~/domains/example.com/laravel
cp .env.example .env
nano .env
```

Set at least:

```
APP_NAME="Nepsus Tech"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=...   # from step 1
DB_USERNAME=...
DB_PASSWORD=...

OPENROUTER_API_KEY=sk-or-...
OPENROUTER_MODEL=nvidia/nemotron-3-super-120b-a12b:free
```

Then:

```bash
php artisan key:generate
```

3. Run the workflow once more: it creates the database tables.

### 7. Point the domain at the app
The domain must open the app's `public` folder, not the app folder itself:

```bash
cd ~/domains/example.com
mv public_html public_html_old
ln -s laravel/public public_html
```

### 8. Create your login
Over SSH, in `~/domains/example.com/laravel`:

```bash
php artisan tinker --execute="(new App\Models\User)->forceFill(['name' => 'Your Name', 'email' => 'you@example.com', 'password' => bcrypt('a-strong-password'), 'email_verified_at' => now()])->save();"
```

### 9. Scheduled jobs (attendance sync)
hPanel → **Advanced → Cron Jobs** → every minute:

```
cd /home/u123456789/domains/example.com/laravel && php artisan schedule:run >> /dev/null 2>&1
```

### 10. Attendance helper on the office PC
In the live app, open **Settings → Attendance device** and download the helper again, so the office PC sends punches to the live site instead of `127.0.0.1`.

---

## Day to day

```bash
git push
```

That's it. To redeploy without changes: GitHub → Actions → deploy → **Run workflow**.
