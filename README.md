# Anicalls website

The Anicalls marketing site plus a small PHP backend for the contact form,
the consultation booking flow (which talks to Google Calendar), and a basic
user login/registration area.

The front end is plain HTML, one `styles.css` shared across every page, and a
little vanilla JS. No build step, no framework. Fonts come from Google Fonts.

## What you need

- PHP 8.1 or newer, with `mysqli`, `curl`, `mbstring` and `openssl`
- MySQL or MariaDB
- Composer
- Apache or any web server that runs PHP

Locally we run it on WAMP, so the instructions below assume the project sits
in `c:\wamp64\www\anicalls-workforce-v2` and is reached at
`http://localhost/anicalls-workforce-v2/`. Adjust paths if your setup differs.

## Getting it running

1. **Clone** into your web root.

   ```
   git clone https://github.com/roza654/anicallscode.git
   ```

2. **Install the PHP packages** (PHPMailer, Google API client, Guzzle):

   ```
   composer install
   ```

3. **Create the two config files.** They hold real credentials so they are
   not in the repo. Copy the templates and fill them in:

   ```
   cp api/google-config.example.php  api/google-config.php
   cp user/config.example.php        user/config.php
   ```

   See [Configuration](#configuration) for what goes in each.

4. **Set up the database** (see [Database](#database) below).

5. **Add the hero videos.** The `.mp4` files in `assests/` are too big for
   git and are not tracked. Drop them into `assests/` if you have them. If
   you don't, nothing breaks, the hero sections just show a dark background
   instead of the video.

6. Open `http://localhost/anicalls-workforce-v2/` in a browser.

## Configuration

### api/ (contact form and booking)

Database and SMTP settings are defined at the top of each handler:
`api/booking.php`, `api/booked-slots.php`, `api/contact.php`,
`api/modal-booking.php`. If your DB user or mail account is different from
the defaults, edit the `define()`s (and the `mysqli_connect()` call in
`booking.php`) in all four. They are intentionally kept in the files rather
than in a shared include, so remember to change all of them together.

Google Calendar is only used by `api/modal-booking.php`, which reads
`api/google-config.php`:

```
GOOGLE_CLIENT_ID
GOOGLE_CLIENT_SECRET
GOOGLE_REFRESH_TOKEN
GOOGLE_CALENDAR_ID   (usually "primary")
```

You get the client ID and secret from the Google Cloud Console under
APIs & Services > Credentials (OAuth 2.0 client). The refresh token you
generate once by running the OAuth consent flow for that client with the
Calendar scope.

### user/ (login, register, password reset)

Everything lives in `user/config.php`: the database connection and the
SMTP account used for verification and reset emails. This can point at the
same database as `api/` or a separate one, whatever you prefer.

A note on SMTP: with Gmail you need an App Password, not your normal
password, and the account has to have 2FA turned on.

## Database

Most tables create themselves the first time the relevant script runs:

| Table              | Created by            |
| ------------------ | --------------------- |
| `contact_inquiries`| `api/contact.php`     |
| `booking_requests` | `api/modal-booking.php`|
| `users`            | `user/db.php`         |

The one that does not is `bookings`, used by `api/booking.php`. Create it
by hand:

```
mysql -u USER -p DBNAME < api/setup-bookings-table.sql
```

The other `.sql` files under `api/` and `user/` are reference schemas for
checking or rebuilding tables manually. You don't need to run them for a
normal setup.

## Deploying

Upload everything that is not covered by `.gitignore`, then on the server:

- `composer install --no-dev`
- create `api/google-config.php` and `user/config.php`, same as local
- upload the `assests/*.mp4` files separately
- make sure `api/logs/` exists and is writable by the web server

The pages link to each other with relative paths, so serving from a
subfolder or a vhost root both work. The canonical and Open Graph URLs in
the page `<head>` still point at `https://www.anicalls.com/`, so update
those if you host it somewhere else long term.

## Things that will trip you up

- A fresh clone can't run the booking or login flows until you create the
  two ignored config files. That's expected.
- The videos aren't in the repo. Missing them is not an error.
- If you rotate a database or SMTP password, `api/` has it in four
  separate files. Search for the old value and replace all of them.
- `api/google-config.php` and `user/config.php` contain live secrets.
  Keep them off any public repo.

## Project layout

```
*.html            each page, standalone
styles.css        shared styles for every page
assests/          images, logos, fonts, videos (mp4 not tracked), small JS
  js/             booking + login modal scripts
api/              contact + booking backend (PHP)
user/             login / auth backend (PHP)
vendor/           Composer packages (not tracked)
composer.json     PHP dependencies
```
