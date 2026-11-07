[![Docker Pulls](https://img.shields.io/docker/pulls/wardy784/erugo)](https://hub.docker.com/r/wardy784/erugo) ![GitHub Repo stars](https://img.shields.io/github/stars/deanward/erugo) ![GitHub Issues or Pull Requests](https://img.shields.io/github/issues/deanward/erugo) ![GitHub Issues or Pull Requests](https://img.shields.io/github/issues-closed/deanward/erugo) ![GitHub commits since latest release](https://img.shields.io/github/commits-since/deanward/erugo/latest) [![license](https://img.shields.io/github/license/dec0dOS/amazing-github-template.svg)](LICENSE)

[![Discord Banner 2](https://img.shields.io/badge/Join%20Us%20On%20Discord-8A2BE2)](https://discord.gg/M74X2wmqY8)

> **This is a fork.** [`hami/Erugo`](https://github.com/hami/Erugo), branch `saru`, is [Erugo](https://github.com/ErugoOSS/Erugo) v0.2.15 plus the share features listed under [What's new in this fork](#whats-new-in-this-fork). Everything else is upstream Erugo. Please report problems with these features here rather than upstream.

# Erugo

**Your Self-Hosted File Sharing Platform**

Erugo is a powerful, self-hosted file-sharing platform that puts you in **complete control** of your data. Built with PHP/Laravel and a Vue.js frontend, it offers secure, customisable file-transfer capabilities through an elegant user interface.

### Why Self-Host?

- **Your Data, Your Rules** — Files stay on your infrastructure. No third-party access, no data mining, no surprises.
- **Deploy Anywhere** — Run on your own server, VPS, or even a Raspberry Pi. Docker makes setup a breeze.
- **Open Source Forever** — MIT licensed. Inspect the code, contribute features, or fork it for your own needs.

### Support Erugo's Development

If you would like to support Erugo's development, please consider donating to the project. Your support helps me maintain and improve the software.

[![ko-fi](https://ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/B0B11BF8EC)

## What's new in this fork

These features build on upstream v0.2.15. Each one lives on its own branch that starts from v0.2.15, so you can take only the ones you want: merge a branch (and whatever it needs) into your copy of Erugo. This `saru` branch is all of them merged together.

| Branch | What it adds | Needs |
| --- | --- | --- |
| `test-harness` | Lets feature tests boot the app repeatedly; a shared `ShareTestCase` | — |
| `locked-shares` | Locked shares: choose what stays hidden, unlock once | `test-harness` |
| `manage-share` | Manage a share after it's made (recipients, link and password), the option not to remember recipients, suggestions of past recipients, and privacy controls. One commit each, in that order. | `locked-shares` |
| `delete-share` | Delete a share now | `test-harness` |
| `expired-download` | Admins, and owners, can download expired shares | `test-harness` |
| `apple-sign-in` | Sign in with Apple | `test-harness` |
| `trusted-proxies` | Trust a reverse proxy named in `TRUSTED_PROXIES` | `test-harness` |
| `theme-footer` | Themes can style the "Powered by" footer | — |

Branches that need only `test-harness` don't touch each other's code, but they do add lines next to each other in shared lists (settings, strings, the System settings page). Merging two of them may need those conflicts resolved by keeping both sides.

### Manage a share after it's made

Each row in My Shares and All Shares has a row of icons: **Recipients** (with a count), **New link**, **Change password**, **Edit details**, **Download** and **Delete**. The first four open the Manage dialog at the matching tab. Below them, **Expire Now** and **Extend** share the width evenly.

- **Details:** rename the share, remove its password, and choose what it hides while locked (see below).
- **Recipients:** everyone the share was sent to. Each person has icons to email them the link again, edit their name or email, or remove them. New recipients can be added, and optionally sent the link straight away. Recipients are recorded when a share is created, unless *Remember recipients on this share* is unticked in the uploader. The default is set under Settings → System → Shares. Shares created before this fork have none until you add them. When adding someone, in the uploader or here, people you've sent shares to before are suggested as you type. You only ever see your own contacts.
- **Link & password:** choose one of the following, then choose who to tell: all recipients, only the ones you tick, or nobody.
  - **New link:** the current link stops working at once.
  - **Change password** (or **Set a password** if the share doesn't have one).
  - **New link and password:** change both together.

  When people are emailed, they get an "Access to your files has changed" message, which can be edited under Email Templates. For password changes there's an option, off by default, to include the new password in the email; otherwise it says the sender will pass it on. To cut someone off for good, remove them from the recipients first.
- API:
  - `PUT /api/shares/{id}` (name, `locked_hidden`, `remove_password`)
  - `POST /api/shares/{id}/change-access` (`new_link`, `password` + `password_confirmation`, `notify`: [recipient ids], `include_password`)
  - `POST|PUT|DELETE /api/shares/{id}/recipients[/{recipientId}]`
  - `POST /api/shares/{id}/recipients/{recipientId}/resend`

### Privacy controls

Upstream Erugo emails recipients but keeps nothing about them. This fork stores them, so these controls help an operator meet GDPR-style expectations. Settings are under Settings → System → Shares.

- **Retention:** a share's recipients are deleted when its files are cleaned up.
- **Forget a recipient** (admins only): removes one email address from every share, for requests to be forgotten. Emails already sent can't be recalled.
- **Suggest past recipients:** on by default; turn it off to stop contacts being reused across shares.
- **Allow new passwords in emails:** on by default; turn it off and passwords are never emailed. When a password is emailed, it sits in the app's database in plain text until the email is sent.

### Choose what a locked share hides

A password-protected share can keep any of these hidden until the password is entered:

- the file list
- the file count and total size
- the message to recipients
- the sender's name
- the share's name (the page shows "Password-protected share" instead)

The expiry always shows.

- **Defaults** are set under Settings → System → Shares → *Hidden on locked shares*. They are copied onto each new share, so changing them later doesn't alter existing shares.
- **Per share:** the uploader's password dialog and the Edit dialog have the same options, under *Hidden until unlocked*.
- API: `POST /api/shares/{share}/unlock` (body `{"password": "..."}`). Hidden fields come back as `null` from `GET /api/shares/{share}`, which also lists them in `hidden`.

### An unlocked share stays unlocked

- Once someone enters the right password, their browser stays unlocked until the share expires. Downloading the whole share or single files needs nothing more.
- Unlocks are remembered with an HttpOnly cookie holding a signature over the share's id, link and password hash. Nothing about the password is stored in the browser.
- Changing the password or regenerating the link locks everyone out again.
- Clicking a single file on a password-protected share now downloads it. Upstream, this didn't send the password.

### Other changes

- Wrong share passwords are rate limited: after 10 wrong tries in 10 minutes for a share from one IP address, further tries are refused for a while. This applies to unlocking and to both kinds of download.
- The password hash is no longer included in the share JSON sent to owners and admins.
- The new interface text is in English only for now; other languages fall back to English.

### Sign in with Apple

Apple is available as a login provider, alongside Google, Microsoft, Authentik and OpenID Connect. Add it under Settings → System → Auth providers. It needs an Apple Developer account and four values:

- **Client ID:** a Services ID with Sign in with Apple turned on. Its website domain and return URL are your Erugo domain and `https://<your domain>/auth/provider/<provider uuid>/callback`.
- **Team ID** and **Key ID:** 10 characters each.
- **Private key:** the contents of the `.p8` file for a key with Sign in with Apple enabled. Pasting it into the one-line field is fine.

Erugo signs a short-lived client secret from the key at each sign-in, so there's no secret to renew. People who choose Hide My Email get a relay address, so they should link Apple from their profile while signed in.

### Delete a share now

Upstream, an expired share's files stay on disk until the cleanup period has passed (30 days by default). Each row in My Shares and All Shares now has a **Delete** icon. After a confirmation, it removes the files at once, ends the link and clears the recipients. The share stays in the list marked as deleted, just as the scheduled cleanup leaves it.

- Admins can delete any share from All Shares.
- Owners can delete their own shares from My Shares. Turn off *Let people delete their own shares* under Settings → System → Shares to leave deleting to admins.
- API: `DELETE /api/shares/{id}`

### Download expired shares

Upstream, nobody can download a share once it has expired, even though its files stay on disk until the cleanup. In this fork the **Download** icon keeps working for any share that hasn't been deleted, whether it has expired, run out of downloads or is password protected:

- **Admins:** any share, from My Shares or All Shares. Turn off *Let admins download expired shares* to stop this.
- **Owners:** their own shares, from My Shares. Turn off *Let people download their own expired shares* to stop this.

Both settings are under Settings → System → Shares and start on. The browser is given a signed link that lasts five minutes. These downloads don't count towards a share's download limit and don't email the owner. Turning a setting off also stops links already handed out.

- API: `POST /api/shares/{id}/download-link` returns the signed `GET /api/shares/{id}/signed-download` link.

### Running behind a reverse proxy

Behind a proxy, upstream Erugo sees every request as plain http from the proxy's own address. So it can't mark its login cookie as secure, it builds http links, and per-visitor limits such as wrong-password tries count everyone as one visitor. Set the `TRUSTED_PROXIES` environment variable to the proxy's address, a range such as `172.16.0.0/12`, several separated by commas, or `*`. Erugo then believes the proxy's `X-Forwarded-*` headers. Unset, nothing changes. The login cookie is now marked secure whenever the request is https.

### Theme footer colours

The "Powered by" line sits on the background image, not on a panel, so the panel colours a theme uses don't always read there. A theme can add an optional `footer` section:

```json
"footer": {
  "background": "rgba(246, 242, 234, 0.9)",
  "text": "rgb(44, 50, 58)",
  "link": "rgb(50, 113, 172)",
  "linkHover": "rgb(30, 80, 130)"
}
```

Every key is optional. Themes without the section look as before.

### Tests

The fork's features have feature tests in `tests/Feature/Shares`, `tests/Feature/Auth` and `tests/Feature/Http`, and each branch passes its own tests on its own. Run them with `php artisan test tests/Feature/Shares tests/Feature/Auth tests/Feature/Http`. They use an in-memory SQLite database and a temporary storage folder.

### Building

There's no published image for this fork yet. To build one:

```bash
git clone -b saru https://github.com/hami/Erugo.git && cd Erugo
docker build -t erugo:saru -f docker/alpine/Dockerfile .
```

Use `image: erugo:saru` in place of `wardy784/erugo` in the compose file below. Existing installs pick up the new database tables and settings automatically when the container starts.

## Quick Start

You can use the example docker-compose.yaml below to run Erugo in a container.

```yaml
services:
  app:
    image: wardy784/erugo:latest
    restart: unless-stopped
    volumes:
      - ./erugo-storage:/var/www/html/storage # Use a dedicated folder
    ports:
      - "9998:80"
```

The above docker-compose.yml provides a basic configuration starting point that will get Erugo up and running with a default sqlite database.

```sh
docker compose up -d
``` 

## Screenshots

![Upload Interface](.github/images/screenshots/main-screen-with-files.png)

_Effortless uploads with multiple file support, progress tracking, and folder support_

![My Shares](.github/images/screenshots/main-screen-with-password-form.png)

_Password protect your shares for extra security_

![Settings](.github/images/screenshots/main-screen-with-reverse-share-req.png)

_Invite guests to upload files to your account with a one-time reverse share token_

![image](.github/images/screenshots/login-screen-with-external-providers.png)

_Login screen with external auth provider support_

## Demo

Check out a sample share on our demo site at [demo.erugo.app/shares/tight-silence-sweet-sea](https://demo.erugo.app/shares/tight-silence-sweet-sea). While uploads are disabled on the demo, you can experience the recipient's view and download the sample file.

### Sponsored by BoxToPlay

<a href="https://www.boxtoplay.com/en/vps-hosting/vps-server?ref=erugo-readme">
  <img src="https://github.com/user-attachments/assets/4101b54c-df05-46db-8939-9ad4acb4f26f" alt="Box to Play" width="240">
</a>

BoxToPlay offers premium VPS and game servers at competitive prices. They have generously contributed server resources to support Erugo's infrastructure, for which I am deeply grateful. Explore their services at [boxtoplay.com](https://www.boxtoplay.com/en/vps-hosting/vps-server?ref=erugo-readme).


## Videos Featuring Erugo

- ["Erugo - Self-Hosted File Sharing Platform" by DB Tech](https://www.youtube.com/watch?v=zqipBHSSPm4)
- ["Ditch WeTransfer! Self-Host Erugo for Secure File Sharing with Docker" by KeepItTechie](https://www.youtube.com/watch?v=FrcBALXDIRU)

## Community Content
- [Installer Erugo avec Docker (french)](https://belginux.com/installer-erugo-avec-docker/)
- [How to Install Erugo on Your Server: Step-by-Step Guide for Secure File Sharing](https://www.linkedin.com/pulse/how-install-erugo-your-server-step-by-step-guide-secure-montinaro-yzbcf)

## Key Features

### Effortless Uploads
- Multiple file support with progress tracking
- Folder uploads that maintain structure
- Large file support with automatic resume
- Drag and drop interface

### Human-Friendly Share Links
- Generate memorable URLs like `you.com/shares/quiet-cloud-shrill-thunder`
- No more cryptic strings — easy to share and remember

### Security & Access Control
- Optional password protection for shares
- Configurable expiration dates
- Download limits per share
- Only authorized users can create shares

### Customizable Branding
- Built-in themes (light and dark modes)
- Custom themes via the web interface
- Logo and color customization
- Easy white-labeling for your organization

### Powerful Dashboard
- Track downloads and monitor activity
- Manage shares, extend expiration, or revoke access
- Easy user management with invite system

### Additional Features
- **Reverse Share** — Invite guests to upload files to your account with a one-time token
- **Email Support** — Full email support for notifications, password reset, and more
- **Interactive Setup** — User-friendly first-run configuration
- **Zero-Configuration** — Reasonable defaults out of the box
- **Automatic Cleanup** — Shares automatically deleted after expiration
- **Translations** — Fully translatable with multiple languages supported

## How It Works

1. **Upload** — Drag and drop files or select them from your device
2. **Configure** — Set optional password protection, expiration dates, and download limits
3. **Share** — Get a clean, human-readable URL to share with anyone
4. **Manage** — Track downloads, revoke access, or extend expiration dates from your dashboard

## Star History

Give this repository a star to keep track of releases and updates. Stars are also great for motivating me to keep adding features!

[![Star History Chart](https://api.star-history.com/svg?repos=DeanWard/erugo&type=Date)](https://star-history.com/#DeanWard/erugo&Date)

## Customization

As an open-source project, Erugo can be tailored to your needs:

- Customize the UI to match your brand
- Modify URL structures and authentication methods
- Extend functionality through code modifications

## Translations

Currently, the following languages are supported:
- English
- French (Thanks to [@zarev](https://github.com/zarevskaya) & [@thibdevan](https://github.com/thibdevan))
- German
- Italian
- Dutch
- Portuguese (Thanks to Distermaer)

Erugo is fully translatable. If you would like to contribute a translation, please join our [Discord server](https://discord.gg/M74X2wmqY8) and drop a message in general channel.

## Development

Erugo is a Laravel application and uses Laravel Sail to run the development environment.

Run the following commands to start the server, run migrations, and start vite in dev mode.

### Generate database
```sh
touch database/database.sqlite
chmod 666 database/database.sqlite
```

### Install laravel dependencies
```sh
composer install
npm install
sail up -d
```

### Generate .env file
```sh
cp .env.example .env
chmod 666 .env
sail artisan key:generate
sail artisan jwt:secret
```

### Run the development server
```sh
sail artisan migrate
sail artisan db:seed
npm run dev
```

## Build it yourself

To build the docker image yourself, run the following command.

```sh
docker build -t erugo:local -f docker/alpine/Dockerfile .
```
If you would like to push the image to Docker Hub, run the following command. You will need to have a Docker Hub account and be logged in.
```sh
DOCKER_HUB_USERNAME=<your-docker-hub-username> ./publish-docker-image.sh
```

## Contributing

We welcome community contributions! Feel free to:

- Submit bug reports and feature requests
- Translate Erugo to your language
- Create pull requests
- Engage in discussions

## License

Erugo is released under the MIT License, ensuring maximum flexibility for both personal and commercial use.

---

🚀 **Ready to take control of your file sharing? Deploy Erugo in minutes and start sharing securely!**

## Disclaimer

### Intended Use
Erugo is designed for legitimate file-sharing purposes such as transferring work assets, design files, media content, and other non-sensitive data between trusted parties. It is not intended for sharing illegal, harmful, or unauthorized content.

### Administrator Responsibility
As a self-hosted application, administrators who deploy Erugo are solely responsible for:
- Compliance with all applicable local, national, and international laws
- Proper configuration and security of their instance
- Monitoring and moderating the content shared through their instance
- Implementing appropriate terms of service for their users

### No Warranty
ERUGO IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

### Recommended Practices
We strongly recommend that administrators:
- Implement access controls to limit who can create shares
- Configure appropriate file size limits
- Set reasonable expiration periods for shares
- Regularly review system logs
- Consider implementing additional monitoring if deploying in production environments

By downloading, installing, or using Erugo, you acknowledge that you have read and understood this disclaimer.
