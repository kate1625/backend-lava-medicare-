# Deploy the backend to Render

## Create the web service

1. Push this backend repository to GitHub.
2. In Render, choose **New > Web Service**, select the repository, and use the `main` branch.
3. Select **Docker** as the runtime and keep the Dockerfile path as `./Dockerfile`.
4. Set the health check path to `/api/health`.
5. Let Render provide `PORT`; the container configures Apache to listen on it.

## Add environment variables in Render

Use the database provider's connection values. Do not copy `.env` into GitHub.

| Variable | Value |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_NAME` | `MediCare+` |
| `APP_KEY` | A new random value; generate with `php -r "echo bin2hex(random_bytes(32));"` |
| `DB_DRIVER` | `mysql` |
| `DB_HOST` | Database host |
| `DB_PORT` | Database port |
| `DB_USER` | Database username |
| `DB_PASSWORD` | Database password |
| `DB_NAME` | Database name |
| `DB_CHARSET` | `utf8mb4` |
| `DB_SSL_CA` | `app/config/certs/aiven-ca.pem` when using the configured Aiven CA |
| `DB_SSL_VERIFY` | `true` |
| `JWT_SECRET` | A new random value, at least 32 characters |
| `REFRESH_TOKEN_KEY` | A different new random value, at least 32 characters |
| `MEDICARE_ACCESS_TOKEN_TTL` | Optional. Session duration in seconds; default `28800` (8 hours), allowed range `300`-`43200` |
| `FRONTEND_URL` | The deployed frontend origin, such as `https://your-site.onrender.com` |
| `CORS_ALLOWED_ORIGINS` | The exact frontend origin; comma-separate additional trusted origins |

Add the email and payment variables from `.env.example` only when those integrations are configured. Keep all API keys and private keys in Render's environment settings.

The database must already contain the MediCare+ tables. This repository intentionally excludes SQL dumps and seeded records; apply required schema changes to the hosted database separately and back it up first.

## Connect the static frontend

The API allows browser requests only from `CORS_ALLOWED_ORIGINS`. The frontend must also send API requests to this service's `https://<backend-name>.onrender.com` URL. The current frontend client uses relative `/api` paths, so it needs an API-base-URL setting or a proxy before a separate static-site deployment can call this backend.
