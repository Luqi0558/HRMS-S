# Deploy Orbit HRMS on EC2 with Docker

This stack exposes only the Vue/Nginx gateway on port 80. The browser loads the
SPA and calls Laravel through the same origin at `/api`, while MySQL and PHP-FPM
remain private Docker-network services.

## Phase 1: validate the Docker stack locally

Do this before copying anything to EC2. It builds the same Vue/Nginx,
Laravel/PHP-FPM, and MySQL services that will run in production.

1. Open your **Ubuntu WSL** terminal. Docker must be available there through
   either Docker Engine installed in Ubuntu or Docker Desktop's WSL integration;
   Docker Desktop itself is not otherwise required. Verify it first:

```bash
docker --version
docker compose version
```

2. Go to the repository (for this machine) and make a local-only deployment
   environment:

```bash
cd /mnt/c/Users/muham/OneDrive/Desktop/orbit-hrms/orbit-hrms
cp docker/.env.local.example docker/.env.local
docker run --rm php:8.2-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Paste the generated value into `APP_KEY` in `docker/.env.local`, then start the
full stack:

```bash
docker compose --env-file docker/.env.local up -d --build
docker compose --env-file docker/.env.local ps
docker compose --env-file docker/.env.local logs -f api
```

Open `http://localhost`. After `api` becomes healthy, initialize the local
tenant and its first administrator (the id must match `VITE_TENANT`):

```bash
docker compose --env-file docker/.env.local exec api php artisan tenant:install orbit --name="Local Orbit" --admin-name="Local Admin" --admin-email="admin@orbit.test" --admin-password="a-long-local-test-password"
```

Verify sign-in, API requests, an uploaded check-in photo/receipt, and a page
refresh. To reset only local Docker data, run `docker compose --env-file
docker/.env.local down -v`; this deletes the local MySQL and storage volumes.

## Phase 2: deploy the tested stack to EC2

## Before deployment

1. Create an Ubuntu 22.04/24.04 EC2 instance with a security group that permits:
   - TCP 22 from your own IP only;
   - TCP 80 from the internet (and TCP 443 if TLS terminates on the instance).
2. Prefer an Application Load Balancer with an ACM certificate for HTTPS. Point
   your DNS record at the load balancer, and allow the EC2 security group to
   receive port 80 only from that load balancer. Do **not** expose MySQL (3306)
   or PHP-FPM (9000).
3. Install Docker Engine and the Docker Compose plugin. You can upload source
   directly from your machine; GitHub and a Git clone are not required.

```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"
# Sign out and back in once so the group change applies.
docker compose version
mkdir -p ~/hrms/{frontend,backend,docker,docs}
```

The EC2 compose override supports your existing folder layout:

```text
~/hrms/
├── frontend/                 # Vue project files
├── backend/                  # Laravel project files
├── docker/                   # deployment Dockerfiles/configuration
├── docker-compose.yml
└── docker-compose.ec2.yml
```

From the local machine, use `rsync` (via WSL or Git Bash on Windows) to upload
the code without `node_modules`, `vendor`, or local `.env` files:

```bash
rsync -av --exclude '.git' --exclude 'node_modules' --exclude 'dist' --exclude '.env' --exclude '.env.*' --exclude 'orbit-hrms-api' --exclude 'docker' --exclude 'docs' ./ ubuntu@YOUR_EC2_IP:~/hrms/frontend/
rsync -av --exclude 'vendor' --exclude 'node_modules' --exclude '.env' --exclude '.env.*' ./orbit-hrms-api/ ubuntu@YOUR_EC2_IP:~/hrms/backend/
rsync -av --exclude '.env.production' ./docker/ ubuntu@YOUR_EC2_IP:~/hrms/docker/
scp docker-compose.yml docker-compose.ec2.yml .dockerignore ubuntu@YOUR_EC2_IP:~/hrms/
```

If source is already copied into `~/hrms/frontend` and `~/hrms/backend`, only
upload the `docker/` directory and the two compose files above.

## Configure production secrets

Create the deployment-only environment file. It is ignored by Git.

```bash
cp docker/.env.production.example docker/.env.production
```

Set a unique `APP_KEY`, database passwords, tenant id, public URL, and SMTP
credentials. To generate an application key without putting it in source
control:

```bash
docker run --rm php:8.2-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

For an ALB-terminated HTTPS deployment, `APP_URL`, `FRONTEND_URL`, and
`FRONTEND_URLS` must all use the final `https://` URL. Keep `VITE_API_URL` as
`/api`; the Docker build sets it automatically.

## First start and tenant provisioning

```bash
docker compose -f docker-compose.yml -f docker-compose.ec2.yml --env-file docker/.env.production up -d --build
docker compose -f docker-compose.yml -f docker-compose.ec2.yml --env-file docker/.env.production ps
docker compose -f docker-compose.yml -f docker-compose.ec2.yml --env-file docker/.env.production logs -f api
```

Once `api` is healthy, create the first company and its super administrator.
Use the same id as `VITE_TENANT`.

```bash
docker compose -f docker-compose.yml -f docker-compose.ec2.yml --env-file docker/.env.production exec api \
  php artisan tenant:install orbit \
  --name="Your Company" \
  --admin-name="Primary Admin" \
  --admin-email="admin@example.com" \
  --admin-password="use-a-long-unique-password"
```

Open the configured public URL and sign in with this account. Store the
administrator password in a password manager; never leave it in shell history
on a shared server.

## Release an update

The API container runs central and tenant migrations on boot. For a controlled
release, upload the changed local source again, rebuild, and watch the API logs
before switching traffic.

```bash
# Repeat the rsync/scp upload commands above, then run:
docker compose -f docker-compose.yml -f docker-compose.ec2.yml --env-file docker/.env.production up -d --build
docker compose -f docker-compose.yml -f docker-compose.ec2.yml --env-file docker/.env.production logs --tail=150 api
```

Back up the named MySQL volume before schema changes. A simple logical backup:

```bash
docker compose -f docker-compose.yml -f docker-compose.ec2.yml --env-file docker/.env.production exec -T mysql \
  mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --events --all-databases \
  > orbit-hrms-$(date +%F).sql
```

Run the command after exporting `MYSQL_ROOT_PASSWORD` in your shell, or replace
it from a secure secret manager. Keep backups off the EC2 disk (for example in
an encrypted S3 bucket with a retention policy).

## Operational notes

- `mysql-data` persists the central database and every tenant database.
- `laravel-storage` persists uploaded files, generated documents, and logs.
- The MySQL app user is intentionally granted DDL privileges because this app
  creates one database per tenant. On RDS, provision an equivalent account;
  `CREATE`, `ALTER`, `DROP`, and normal DML are required for tenant databases.
- Configure a real SMTP provider before relying on leave, medical, or invite
  emails. Until then, use `MAIL_MAILER=log` rather than silently losing mail.
- For workers/queued mail in a later phase, set `QUEUE_CONNECTION=redis` and add
  a Redis service plus a dedicated `php artisan queue:work` container.
