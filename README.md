# Timers

A small, self-hosted web page for keeping track of timers that run for minutes, hours or several days. Each timer has a name, a color and a live countdown. When a timer runs out it keeps counting into the negative, so you can see how long ago it finished.

Everything lives in a single PHP file with no database and no dependencies. Timers are stored on the server, so they keep running when the browser is closed and every computer on the network sees the same list.

## Features

- **Multiple named timers** with a live countdown, e.g. `2d 04:13:09`
- **Two ways to set a timer:** enter a duration in days, hours and minutes, or click **Pick end time** to choose a date and time from a calendar
- **Negative countdown** once a timer is done (`−00:12:40`), with the card turning red and showing when it finished
- **Colors:** pick one of eight colors per timer, shown as the timer's left highlight bar
- **Restart** a timer to run its full duration again from now
- **Delete** with an inline confirmation: the button changes to **Sure?** with a **Cancel** button next to it
- **Browser notifications** when a timer runs out (see [Notifications](#notifications) for requirements)
- **Shared across devices:** the page checks the server once a minute and updates the list when timers are added, restarted or deleted elsewhere
- **Accurate over long periods:** countdowns use the server's clock, so a device with the wrong time doesn't throw them off
- **Command-line overview** with `timers.sh` (see [Command line](#command-line))
- Dark theme, works on desktop and mobile

## Requirements

- PHP 7.4 or newer
- Write access for PHP in the project folder (to create `timers.json`)
- Optional: `jq` for the command-line script

## Getting started

Clone the repository and start PHP's built-in web server in the project folder:

```bash
git clone https://github.com/<you>/timers.git
cd timers
php -S localhost:8000
```

Open http://localhost:8000 in your browser.

### Access from other computers on your network

Bind the server to the machine's network address (or `0.0.0.0` for all interfaces):

```bash
php -S 192.168.0.1:8000
```

Then open `http://192.168.0.1:8000` from any computer on the same network. If it doesn't connect, allow the port through the server's firewall, for example `sudo ufw allow 8000/tcp` on Ubuntu.

The built-in server stops when the terminal closes. Run it in `tmux` or `screen`, use `nohup`, or set up a systemd service to keep it running.

You can also drop `index.php` into any regular web server with PHP (Apache, nginx with PHP-FPM, shared hosting).

## How it works

`index.php` handles everything: it shows the page, and it handles adding, restarting and deleting timers through simple form posts. After each action the page redirects back to itself, so refreshing never repeats an action.

Timers are saved in `timers.json` next to `index.php`. The file is created automatically and every write is locked, so two people changing timers at the same time won't corrupt it. Each timer stores its start time and duration rather than a remaining time, which is why timers stay accurate across days, reboots and closed browsers:

```json
[
    {
        "id": "a1b2c3d4e5f6",
        "name": "Something great happens",
        "duration": 86400,
        "start": 1790000000,
        "color": "#3CCFC0"
    }
]
```

`start` is a Unix timestamp and `duration` is in seconds. A timer ends at `start + duration`. Restarting a timer sets `start` to the current time.

When you use **Pick end time**, the timer is saved with a duration from now until the chosen moment. Restarting it later runs that same length again from the current time.

In the browser, a small script updates the countdowns every second. Once a minute it fetches the page in the background and replaces only the timer list if `timers.json` has changed, so anything you're typing in the add form is kept.

## Notifications

Click **Turn on notifications** next to the heading and allow notifications when the browser asks. You'll get a notification each time a timer runs out while the page is open, including timers that were restarted.

### Requirements

Browsers only allow notifications on secure pages: sites served over **HTTPS**, or **localhost**. This means:

- `http://localhost:8000` on the server itself: notifications work
- `http://192.168.0.1:8000` from another computer: notifications are blocked, and the page shows *"Notifications need HTTPS or localhost."*

Notifications also need the page to be open in a tab (a background tab is fine). Browsers slow down timers in background tabs, so a notification can arrive up to about a minute late if the tab has been hidden for a while.

### Fix 1: allow the address in the browser (quick, per browser)

**Chrome / Edge**

1. Go to `chrome://flags/#unsafely-treat-insecure-origin-as-secure` (Edge: `edge://flags/#unsafely-treat-insecure-origin-as-secure`)
2. Add `http://192.168.0.1:8000` to the text box
3. Set the flag to **Enabled** and restart the browser

**Firefox**

1. Go to `about:config`
2. Find `dom.securecontext.allowlist` and add the server's address
3. Reload the page

This has to be done on every computer and browser that should show notifications.

### Fix 2: serve the page over HTTPS (proper, works everywhere)

Put a reverse proxy with HTTPS in front of the PHP server. [Caddy](https://caddyserver.com) makes this short. Run PHP on the local interface only:

```bash
php -S 127.0.0.1:8000
```

Create a `Caddyfile`:

```
https://192.168.0.1:8443 {
    tls internal
    reverse_proxy 127.0.0.1:8000
}
```

Start Caddy with `caddy run`, then open `https://192.168.0.1:8443`. With `tls internal`, Caddy uses its own local certificate authority, so each client computer needs to trust Caddy's root certificate once (run `caddy trust` on the server, and import the root certificate from Caddy's data directory on other machines). If you have a domain name pointing to the server, Caddy can get a regular trusted certificate instead.

## Command line

`timers.sh` prints all timers as a table, sorted by end time:

```bash
chmod +x timers.sh
./timers.sh
```

```
NAME                                      ENDS                   TIME LEFT
----                                      ----                   ---------
Tea                                       Thu 01 Oct 2026 20:06  -00:07:29
Laundry                                   Thu 01 Oct 2026 21:13  00:59:51
Something great happens                   Sun 04 Oct 2026 03:47  2d 07:33:11
```

In a terminal, each name is shown in the timer's color from the website and finished timers show their time left in red. Colors are left out when the output is redirected to a file. Pass a path to read a different file: `./timers.sh /path/to/timers.json`.

The script needs `jq`: `sudo apt install jq` (Debian/Ubuntu), `sudo pacman -S jq` (Arch Linux) or `brew install jq` (macOS). It uses exact 24-bit colors when your terminal reports support for them and the nearest 256-color match otherwise. Over SSH, `COLORTERM=truecolor ./timers.sh` forces the exact colors.

## Files

| File          | Purpose                                           |
|---------------|---------------------------------------------------|
| `index.php`   | The whole web app: page, styles, scripts, storage |
| `timers.sh`   | Prints the timers as a table in the terminal      |
| `timers.json` | Timer data, created automatically (not committed) |

Consider adding `timers.json` to `.gitignore` so your own timers don't end up in the repository.

## Security notes

- There is no login. Anyone who can open the page can add, restart and delete timers. Keep it on a trusted network.
- PHP's built-in server is meant for development and small private use. Don't expose it directly to the internet.
- `timers.json` sits in the web folder, so it can be downloaded at `/timers.json`. That's harmless for timer names, but don't put anything private in them, or block the file in your web server's configuration.
