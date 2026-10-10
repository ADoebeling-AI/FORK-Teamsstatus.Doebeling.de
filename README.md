# Teams status icon

A small PHP script that shows your Microsoft Teams presence as an SVG icon, for example on your website.

The script always returns an icon. If it can read the presence of the user from Microsoft Graph, a badge in the bottom right corner shows the status, just like in Teams.

## Usage

```html
<a href="https://teams.microsoft.com/l/chat/0/0?users=name@example.com">
  <img src="https://teamsstatus.example.com/teamsstatus.php?user=name@example.com&style=monochrom-outline"
       width="32" height="32" alt="Chat with me on Microsoft Teams">
</a>
```

| Parameter | Values | Default |
| --- | --- | --- |
| `user` | E-mail address of a user in the allowlist | none: icon without badge |
| `style` | `monochrom-outline`, `monochrom-filled`, `teams` (Teams colours) | `monochrom-outline` |
| `color` | Colour of the monochrome styles as hex code without `#`, e.g. `FFFFFF` for dark backgrounds | `currentColor` (black in an `<img>`) |

The icon is 32 × 32 pixels by default and scales to any size, e.g. with `width` and `height` on the `<img>` tag.

### Badges

| Badge | Teams status |
| --- | --- |
| Green with check mark | Available |
| Yellow with clock | Away, Be right back, Available but inactive |
| Red | Busy, In a call, In a meeting |
| Red with bar | Do not disturb, Presenting |
| Grey ring with cross | Offline |
| Purple ring with arrow | Out of office |
| No badge | Status unknown, user not in the allowlist, or an error |

## How it works

1. `teamsstatus.php` looks up the e-mail address in the allowlist in `config.php`.
2. It gets an app-only access token from Microsoft Entra ID (client credentials flow).
3. It reads the presence with [`GET /users/{id}/presence`](https://learn.microsoft.com/graph/api/presence-get) from Microsoft Graph.
4. It draws the icon and the badge as SVG.

The access token and each status are cached in `.cache/`. A status is cached for 30 seconds by default (`cache_ttl`). Errors are cached as well, so a broken setup does not flood Microsoft Graph with requests. The response itself is sent with `Cache-Control: no-store`, so browsers always ask for the current status.

## Requirements

- PHP 8.1 or newer with the `curl` extension
- Apache with `mod_rewrite` and `.htaccess` support
- A Microsoft 365 tenant and an admin account that can grant admin consent

## Setup

### 1. Register an app in Microsoft Entra ID

Microsoft Entra ID is the new name of Azure Active Directory (Azure AD).

1. Open the [Microsoft Entra admin center](https://entra.microsoft.com) and go to **Entra ID → App registrations → New registration**.
2. Enter a name, e.g. `Teams status icon`. Keep **Single tenant** and leave the redirect URI empty.
3. Go to **API permissions → Add a permission → Microsoft Graph → Application permissions**, select **Presence.Read.All** and click **Add permissions**.
4. Click **Grant admin consent for …**.
5. Go to **Certificates & secrets → New client secret**. Copy the **Value** (not the Secret ID). Note the expiry date: you need a new secret before it expires.
6. On the **Overview** page, copy the **Application (client) ID** and the **Directory (tenant) ID**.

### 2. Find the object IDs of the users

In the Entra admin center, open **Users**, select a user and copy the **Object ID**.

### 3. Configure

Copy `config.example.php` to `config.php` and fill in the IDs, the client secret and the allowlist:

```php
'users' => [
    'name@example.com' => '00000000-0000-0000-0000-000000000000',
],
```

### 4. Upload

Upload `teamsstatus.php`, `.htaccess` and `config.php` to the web root of a domain or subdomain. The script creates the `.cache/` directory itself, so PHP needs write access to the web root.

All other requests to the domain redirect to this GitHub repository. If you run your own copy, change the URL in `.htaccess`.

Open `teamsstatus.php?user=name@example.com` in your browser to test it. If the icon has no badge, look for lines starting with `teamsstatus:` in the PHP error log.

Instead of steps 3 and 4 you can let GitHub Actions do the work, see the next section.

## Deployment with GitHub Actions

The workflow [`deploy.yml`](.github/workflows/deploy.yml) runs on every push to `main`. You can also start it by hand under **Actions → Deploy → Run workflow**. It:

1. checks the PHP syntax,
2. writes `config.php` from the repository settings below,
3. uploads `teamsstatus.php`, `.htaccess` and `config.php` with FTPS (FTP over TLS, the server certificate is checked),
4. checks that the icon URL returns an SVG (optional).

Each file is uploaded to a temporary name first and then renamed, so the icon is never missing during an upload. The workflow never deletes files on the server, so the cache survives. If you remove a file from the repository, delete it on the server by hand.

Add these settings under **Settings → Secrets and variables → Actions**. Without `FTP_HOST` the workflow skips the upload, e.g. in forks.

| Name | Type | Example |
| --- | --- | --- |
| `FTP_HOST` | Variable | `www123.your-server.de` |
| `FTP_USER` | Variable | User name of the FTP account |
| `FTP_PASSWORD` | Secret | Password of the FTP account |
| `FTP_DIR` | Variable, optional | Target directory, default `/` |
| `ENTRA_TENANT_ID` | Variable | Directory (tenant) ID |
| `ENTRA_CLIENT_ID` | Variable | Application (client) ID |
| `ENTRA_CLIENT_SECRET` | Secret | Value of the client secret |
| `TEAMSSTATUS_USERS` | Variable | `{"name@example.com": "00000000-0000-0000-0000-000000000000"}` |
| `TEAMSSTATUS_URL` | Variable, optional | `https://teamsstatus.example.com/teamsstatus.php` |

Use a separate FTP account that can only access the directory of your (sub)domain, and keep `FTP_DIR` at `/`. Then a wrong setting can never overwrite other websites in the same web space.

When the client secret expires, add the new value as `ENTRA_CLIENT_SECRET` and run the workflow again.

### Hetzner Webhosting

All Hetzner web hosting packages support FTPS. SSH is only available in larger packages, so the workflow does not need it.

- **Host:** the server name `wwwXXX.your-server.de` from the access details in konsoleH. The TLS certificate is valid for this name, not for your own domain.
- **FTP account:** create an additional FTP account in konsoleH and limit it to the directory of the subdomain.
- **PHP:** choose PHP 8.1 or newer for the subdomain.

## Privacy and security

- With **Presence.Read.All**, the app can read the presence of every user in your tenant. The script only answers for the addresses in the allowlist. For all other addresses it returns the icon without a badge, so nobody can probe the status of your colleagues.
- The presence is personal data, and it is public for everyone who knows the URL. Anyone could request it regularly and record when you are online. Only add people to the allowlist who agree to this. If your company has a works council, involve it.
- `.cache/` holds the access token. `.htaccess` blocks all access to it.
- `config.php` holds the client secret. It is excluded from Git by `.gitignore`.
- The client secret expires after 24 months at most. After that, the icon has no badge until you add a new secret.

## Trademarks

Microsoft and Microsoft Teams are trademarks of the Microsoft group of companies. This project is not affiliated with or endorsed by Microsoft.

## License

[MIT](LICENSE)
