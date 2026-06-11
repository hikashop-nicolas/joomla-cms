# Customize-mode demo stack

A self-contained Docker stack that builds the `feature/customize-mode` Joomla fork, installs it with a
small amount of demo content, and serves it ready to show customize mode. Intended for a **private**
demo shared with trusted reviewers (no public abuse hardening / reset).

> Demo-only, not part of the eventual joomla-cms pull request.

## What it does

- `Dockerfile` clones the fork branch and builds it (composer for PHP vendors, then `npm ci` +
  `npm run update` for the media assets, which is where the customize engine and plugins compile).
- `docker-compose.yml` runs MySQL plus the Joomla container, with named volumes so the database and the
  webroot (including any template/language overrides made through customize mode) survive restarts.
- `entrypoint.sh` populates the webroot on first run, waits for MySQL, installs Joomla non-interactively,
  removes the installer, and runs `seed.php`.
- `seed.php` creates a few featured articles so the front page has layouts and modules worth editing.
  The five `customize` plugins already ship enabled on a fresh install.

## Run it locally

```
cp .env.example .env      # then edit the passwords (and HTTP_PORT if 8080 is taken)
docker compose up -d --build
```

Open `http://localhost:8080` for the site and `http://localhost:8080/administrator` for the admin
(log in with `ADMIN_USER` / `ADMIN_PASSWORD` from `.env`). Edit a menu item and click **Customize**.

First build takes a few minutes. Iterate the seed without rebuilding the image (it is mounted):

```
docker compose exec joomla php /demo/seed.php
```

## Host it free on Oracle Cloud (Always Free)

The Always Free tier gives a real, free-forever Arm VM with enough headroom for this.

1. **Sign up** at cloud.oracle.com (a card is required for verification; Always Free is not charged).
2. **Upgrade the account to Pay-As-You-Go.** This removes Oracle's idle-instance reclamation (a
   low-traffic demo would otherwise get stopped after 7 days idle) and still costs **$0** as long as you
   stay within Always-Free limits. Set a budget alert to be safe.
3. **Create an Ampere A1 (Arm) instance** in **US East (Ashburn)** or **US West (Phoenix)** (the regions
   with the most consistent Arm capacity). A 2 OCPU / 12 GB shape with a 50 GB boot volume, Ubuntu 22.04,
   is plenty. Add your SSH key.
4. **Open the web port** in the instance's subnet security list (ingress TCP 80, or 443 if you terminate
   TLS on the box). If you use the Cloudflare Tunnel below you do not need to open any port.
5. **Install Docker** on the VM:
   ```
   curl -fsSL https://get.docker.com | sh
   sudo usermod -aG docker $USER && newgrp docker
   ```
6. **Get this stack onto the VM and start it:**
   ```
   git clone --depth 1 -b feature/customize-mode https://github.com/hikashop-nicolas/joomla-cms.git
   cd joomla-cms/demo
   cp .env.example .env      # edit the passwords; set HTTP_PORT=80 to serve on the default port
   docker compose up -d --build
   ```

The site is then at `http://<vm-public-ip>` (or `:8080` if you kept that port).

### A clean HTTPS URL (optional)

A Cloudflare Tunnel gives a stable `https://` link with no open ports or certificates, you just need a
domain on Cloudflare's free plan (a cheap one from Porkbun/Namecheap works):

```
curl -fsSL https://pkg.cloudflare.com/cloudflared/install.sh | sudo bash   # or download cloudflared
cloudflared tunnel login
cloudflared tunnel create joomla-demo
cloudflared tunnel route dns joomla-demo demo.example.com
cloudflared tunnel run --url http://localhost:8080 joomla-demo            # run as a service to persist
```

Then share `https://demo.example.com` and the admin login privately with the reviewers. For a quick
private share without a domain, plain `http://<vm-ip>` is fine.

## Notes

- To reset the demo to a clean state: `docker compose down -v` (drops the volumes) then `up -d --build`.
- The admin password lives only in `.env` (gitignored). Use a non-trivial one even for a private demo.
