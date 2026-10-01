<?php
// Simple multi-day timers. Timers are saved in timers.json next to this file,
// so they keep running even when the browser is closed.

$file = __DIR__ . '/timers.json';

// Colours you can pick for a timer (chosen to stand out on the dark background)
$colors = [
    '#F2B134' => 'Mustard',
    '#FF7A6B' => 'Coral',
    '#F27EC1' => 'Pink',
    '#A98BFF' => 'Violet',
    '#5AA9FF' => 'Blue',
    '#3CCFC0' => 'Teal',
    '#7BD66B' => 'Green',
    '#B8C4D2' => 'Silver',
];
$defaultColor = '#F2B134';

function e($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function load_timers($file) {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

// Read, change and save the timer list safely (locked, so two requests can't clash)
function update_timers($file, callable $change) {
    $fp = fopen($file, 'c+');
    if (!$fp) die('Cannot write timers.json. Check that this folder is writable.');
    flock($fp, LOCK_EX);
    $timers = json_decode(stream_get_contents($fp), true);
    if (!is_array($timers)) $timers = [];
    $timers = array_values($change($timers));
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($timers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function human_duration($seconds) {
    $d = intdiv($seconds, 86400);
    $h = intdiv($seconds % 86400, 3600);
    $m = intdiv($seconds % 3600, 60);
    $parts = [];
    if ($d) $parts[] = $d . 'd';
    if ($h) $parts[] = $h . 'h';
    if ($m) $parts[] = $m . 'm';
    return $parts ? implode(' ', $parts) : '0m';
}

$error = '';
$form = ['name' => '', 'days' => '', 'hours' => '', 'minutes' => '', 'color' => $defaultColor, 'end_at' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = $_POST['id'] ?? '';

    if ($action === 'add') {
        $form = [
            'name'    => trim($_POST['name'] ?? ''),
            'days'    => $_POST['days'] ?? '',
            'hours'   => $_POST['hours'] ?? '',
            'minutes' => $_POST['minutes'] ?? '',
            'color'   => $_POST['color'] ?? $defaultColor,
            'end_at'  => trim($_POST['end_at'] ?? ''),
        ];
        if (!ctype_digit($form['end_at'])) $form['end_at'] = '';
        if (!isset($colors[$form['color']])) $form['color'] = $defaultColor;
        $days = max(0, (int)$form['days']);
        $hours = max(0, (int)$form['hours']);
        $minutes = max(0, (int)$form['minutes']);
        $duration = $days * 86400 + $hours * 3600 + $minutes * 60;
        // A picked end date/time replaces the days/hours/minutes fields
        if ($form['end_at'] !== '') $duration = (int)$form['end_at'] - time();

        if ($form['name'] === '') {
            $error = 'Give the timer a name.';
        } elseif ($form['end_at'] !== '' && $duration <= 0) {
            $error = 'Pick an end time in the future.';
        } elseif ($duration <= 0) {
            $error = 'Set a duration of at least 1 minute.';
        } else {
            $name = function_exists('mb_substr') ? mb_substr($form['name'], 0, 100) : substr($form['name'], 0, 100);
            $color = $form['color'];
            update_timers($file, function ($timers) use ($name, $duration, $color) {
                $timers[] = [
                    'id'       => bin2hex(random_bytes(6)),
                    'name'     => $name,
                    'duration' => $duration,
                    'start'    => time(),
                    'color'    => $color,
                ];
                return $timers;
            });
        }
    } elseif ($action === 'restart') {
        update_timers($file, function ($timers) use ($id) {
            foreach ($timers as &$t) {
                if ($t['id'] === $id) $t['start'] = time();
            }
            return $timers;
        });
    } elseif ($action === 'delete') {
        update_timers($file, function ($timers) use ($id) {
            return array_filter($timers, fn($t) => $t['id'] !== $id);
        });
    }

    // Redirect after a successful action so refreshing doesn't repeat it
    if ($error === '') {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

$timers = load_timers($file);
// Soonest to finish first; finished timers end up at the top
usort($timers, fn($a, $b) => ($a['start'] + $a['duration']) <=> ($b['start'] + $b['duration']));
// Changes whenever timers.json changes, so open pages can tell when to refresh their list
$version = file_exists($file) ? md5_file($file) : 'none';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0F1B2B">
    <title>Timers</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700&family=Source+Sans+3:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            color-scheme: dark;
            --ink: #E6EDF5;      /* main text */
            --sky: #0F1B2B;      /* page background */
            --paper: #1A2A3F;    /* cards and form */
            --mustard: #F2B134;
            --mustard-hover: #FFC44D;
            --on-mustard: #0F1B2B;
            --muted: #9AABBF;
            --line: #33496A;
            --over: #FF8A7A;
            --over-bg: #3A1F27;
            --display: 'Bricolage Grotesque', 'Segoe UI', system-ui, sans-serif;
            --body: 'Source Sans 3', 'Segoe UI', system-ui, sans-serif;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 2.5rem 1.25rem 4rem;
            font: 1.0625rem/1.5 var(--body);
            color: var(--ink); background: var(--sky);
        }
        .wrap { max-width: 44rem; margin: 0 auto; }
        .page-head {
            display: flex; flex-wrap: wrap; gap: .75rem 1rem;
            align-items: center; justify-content: space-between; margin: 0 0 1.5rem;
        }
        h1 { font: 700 2.5rem/1.1 var(--display); margin: 0; }
        .notify { color: var(--muted); font-size: .9rem; max-width: 22rem; }
        .notify [hidden] { display: none; }

        /* Add form */
        .add {
            display: grid; gap: .75rem;
            grid-template-columns: 1fr repeat(3, 5.5rem) auto;
            align-items: end;
            background: var(--paper); padding: 1.25rem; border-radius: 10px;
        }
        .add [hidden] { display: none; }
        .add > label { display: grid; gap: .25rem; font-size: .9rem; font-weight: 600; }
        .add input {
            font: inherit; width: 100%; padding: .55rem .65rem; color: var(--ink);
            border: 2px solid var(--line); border-radius: 6px; background: var(--paper);
        }
        .add input:focus { border-color: var(--mustard); outline: none; }
        .add input::placeholder { color: var(--muted); opacity: .7; }
        .end-btn { grid-column: 5; grid-row: 1; }
        .end-chip {
            grid-column: 2 / 5; grid-row: 1;
            display: flex; align-items: center; justify-content: space-between; gap: .5rem;
            padding: .4rem .4rem .4rem .8rem; border: 2px solid var(--line); border-radius: 6px;
            font-weight: 600;
        }
        .end-chip button { padding: .3rem .7rem; }
        dialog {
            background: var(--paper); color: var(--ink);
            border: 1px solid var(--line); border-radius: 12px;
            padding: 1.25rem; width: min(22rem, calc(100% - 2rem));
        }
        dialog::backdrop { background: rgba(5, 10, 18, .7); }
        dialog h2 { font: 700 1.3rem/1.2 var(--display); margin: 0 0 1rem; }
        dialog label { display: grid; gap: .25rem; font-size: .9rem; font-weight: 600; }
        dialog input {
            font: inherit; padding: .55rem .65rem; color: var(--ink); background: var(--sky);
            border: 2px solid var(--line); border-radius: 6px;
        }
        dialog input:focus { border-color: var(--mustard); outline: none; }
        .dialog-error { color: var(--over); font-weight: 600; margin: .5rem 0 0; min-height: 1.5em; }
        .dialog-actions { display: flex; justify-content: flex-end; gap: .5rem; margin-top: .75rem; }
        .colors {
            grid-column: 1 / -2; border: 0; margin: 0; padding: 0;
            display: flex; flex-wrap: wrap; gap: .5rem; align-items: center;
        }
        .colors legend { float: left; font-size: .9rem; font-weight: 600; margin-right: .5rem; padding: 0; }
        .swatch { position: relative; cursor: pointer; }
        .swatch input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
        .swatch .dot {
            display: block; width: 1.75rem; height: 1.75rem; border-radius: 50%;
            background: var(--c);
        }
        .swatch input:checked + .dot { box-shadow: 0 0 0 3px var(--paper), 0 0 0 5px var(--ink); }
        .swatch input:focus-visible + .dot { outline: 3px solid var(--ink); outline-offset: 5px; }
        .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
        .error { grid-column: 1 / -1; margin: 0; color: var(--over); font-weight: 600; }

        button {
            font: 600 .95rem var(--body); cursor: pointer;
            padding: .6rem 1rem; border-radius: 6px; border: 2px solid transparent;
        }
        .primary { background: var(--mustard); color: var(--on-mustard); }
        .primary:hover { background: var(--mustard-hover); }
        .ghost { background: transparent; color: var(--ink); border-color: var(--line); }
        .ghost:hover { border-color: var(--muted); }
        .ghost.danger:hover { border-color: var(--over); color: var(--over); }
        :focus-visible { outline: 3px solid var(--mustard); outline-offset: 2px; }

        /* Timer list */
        .list { list-style: none; padding: 0; margin: 2rem 0 0; display: grid; gap: .75rem; }
        .timer {
            display: grid; grid-template-columns: 1fr auto; gap: .25rem 1rem;
            align-items: center;
            background: var(--paper); padding: 1rem 1.25rem; border-radius: 10px;
            border-left: 6px solid var(--accent, var(--mustard));
        }
        .timer.done { background: var(--over-bg); }
        .name { font: 600 1.15rem/1.3 var(--display); margin: 0; overflow-wrap: anywhere; }
        .meta { color: var(--muted); font-size: .9rem; margin: 0; }
        .clock {
            grid-row: span 2;
            font: 700 clamp(1.5rem, 5vw, 2.1rem)/1 var(--display);
            font-variant-numeric: tabular-nums; text-align: right; white-space: nowrap;
        }
        .done .clock { color: var(--over); }
        .actions { grid-column: 1 / -1; display: flex; gap: .5rem; margin-top: .5rem; }
        .actions form { margin: 0; }
        .delete-form { display: flex; gap: .5rem; }
        .danger.armed { border-color: var(--over); color: var(--over); background: var(--over-bg); }
        .empty { margin-top: 2rem; color: var(--muted); }

        @media (max-width: 600px) {
            .add { grid-template-columns: repeat(3, 1fr); }
            .add .name-field, .add .primary, .add .colors, .add .end-btn, .add .end-chip { grid-column: 1 / -1; grid-row: auto; }
            .clock { grid-row: auto; grid-column: 1 / -1; text-align: left; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="page-head">
        <h1>Timers</h1>
        <div class="notify">
            <button class="ghost" type="button" id="notify-btn" hidden>Turn on notifications</button>
            <span id="notify-status"></span>
        </div>
    </div>

    <form class="add" method="post">
        <input type="hidden" name="action" value="add">
        <label class="name-field">Name
            <input name="name" maxlength="100" required value="<?= e($form['name']) ?>" placeholder="e.g. Something great happens">
        </label>
        <label class="dur">Days <input name="days" type="number" min="0" inputmode="numeric" value="<?= e($form['days']) ?>" placeholder="0"></label>
        <label class="dur">Hours <input name="hours" type="number" min="0" inputmode="numeric" value="<?= e($form['hours']) ?>" placeholder="0"></label>
        <label class="dur">Minutes <input name="minutes" type="number" min="0" inputmode="numeric" value="<?= e($form['minutes']) ?>" placeholder="0"></label>
        <input type="hidden" name="end_at" id="end-at" value="<?= e($form['end_at']) ?>">
        <div class="end-chip" id="end-chip" hidden>
            <span id="end-chip-text"></span>
            <button class="ghost" type="button" id="end-clear">Clear</button>
        </div>
        <button class="ghost end-btn" type="button" id="end-open">Pick end time</button>
        <fieldset class="colors">
            <legend>Color</legend>
            <?php foreach ($colors as $hex => $label): ?>
                <label class="swatch" title="<?= e($label) ?>">
                    <input type="radio" name="color" value="<?= e($hex) ?>" <?= $form['color'] === $hex ? 'checked' : '' ?>>
                    <span class="dot" style="--c: <?= e($hex) ?>"></span>
                    <span class="sr"><?= e($label) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <button class="primary" type="submit">Add timer</button>
        <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
    </form>

    <dialog id="end-dialog" aria-labelledby="end-title">
        <h2 id="end-title">When should it end?</h2>
        <label>End date and time
            <input type="datetime-local" id="end-picker">
        </label>
        <p class="dialog-error" id="end-error"></p>
        <div class="dialog-actions">
            <button class="ghost" type="button" id="end-cancel">Cancel</button>
            <button class="primary" type="button" id="end-confirm">Use this time</button>
        </div>
    </dialog>

    <div id="timer-list" data-version="<?= e($version) ?>">
    <?php if (!$timers): ?>
        <p class="empty">No timers yet. Add one above to start counting down.</p>
    <?php else: ?>
        <ul class="list">
            <?php foreach ($timers as $t): ?>
                <?php $accent = (isset($t['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $t['color'])) ? $t['color'] : $defaultColor; ?>
                <li class="timer" style="--accent: <?= e($accent) ?>" data-id="<?= e($t['id']) ?>" data-end="<?= (int)($t['start'] + $t['duration']) ?>">
                    <p class="name"><?= e($t['name']) ?></p>
                    <span class="clock" aria-live="off">--:--:--</span>
                    <p class="meta">
                        <?= e(human_duration((int)$t['duration'])) ?> timer ·
                        <span class="end-text">…</span>
                    </p>
                    <div class="actions">
                        <form method="post">
                            <input type="hidden" name="action" value="restart">
                            <input type="hidden" name="id" value="<?= e($t['id']) ?>">
                            <button class="ghost" type="submit">Restart</button>
                        </form>
                        <form method="post" class="delete-form">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= e($t['id']) ?>">
                            <button class="ghost danger" type="submit">Delete</button>
                            <button class="ghost cancel" type="button" hidden>Cancel</button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    </div>
</div>

<script>
// Use the server's clock so countdowns are correct even if this device's clock is off
const offset = <?= time() ?> * 1000 - Date.now();
const pad = n => String(n).padStart(2, '0');

function format(totalSeconds) {
    const sign = totalSeconds < 0 ? '−' : '';
    let s = Math.abs(totalSeconds);
    const d = Math.floor(s / 86400); s %= 86400;
    const h = Math.floor(s / 3600);  s %= 3600;
    const m = Math.floor(s / 60);    s %= 60;
    return sign + (d ? d + 'd ' : '') + pad(h) + ':' + pad(m) + ':' + pad(s);
}

function formatDate(ms) {
    return new Date(ms).toLocaleString(undefined, {
        weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'
    });
}

let firstTick = true;
// Remembers which timer endings were already notified, by id + end time,
// so it survives the list being refreshed from the server
const notified = new Set();

function tick() {
    const now = Date.now() + offset;
    document.querySelectorAll('.timer').forEach(el => {
        const end = Number(el.dataset.end) * 1000;
        const diff = end - now;
        const done = diff <= 0;
        // Notify once when a timer runs out while the page is open
        // (timers that were already finished when the page loaded stay quiet)
        const key = el.dataset.id + '-' + el.dataset.end;
        if (done && !notified.has(key)) {
            notified.add(key);
            if (!firstTick) notifyDone(el);
        }
        // Round up while counting down, so it hits 00:00:00 exactly when it ends
        const secs = done ? -Math.floor(-diff / 1000) : Math.ceil(diff / 1000);
        el.querySelector('.clock').textContent = format(secs);
        el.querySelector('.end-text').textContent = (done ? 'Finished ' : 'Ends ') + formatDate(end);
        el.classList.toggle('done', done);
    });
    firstTick = false;
}

// Browser notifications
const notifyBtn = document.getElementById('notify-btn');
const notifyStatus = document.getElementById('notify-status');
const canNotify = 'Notification' in window && window.isSecureContext;

function notifyDone(el) {
    if (!canNotify || Notification.permission !== 'granted') return;
    const name = el.querySelector('.name').textContent;
    const n = new Notification('Timer finished: ' + name, {
        body: 'Ended ' + formatDate(Number(el.dataset.end) * 1000),
        // Include the end time so a restarted timer gets a fresh notification
        // instead of silently replacing the previous one
        tag: 'timer-' + el.dataset.id + '-' + el.dataset.end,
    });
    n.onclick = () => { window.focus(); n.close(); };
}

function showNotifyState() {
    notifyBtn.hidden = true;
    if (!('Notification' in window)) {
        notifyStatus.textContent = 'This browser does not support notifications.';
    } else if (!window.isSecureContext) {
        notifyStatus.textContent = 'Notifications need HTTPS or localhost.';
    } else if (Notification.permission === 'granted') {
        notifyStatus.textContent = 'Notifications on';
    } else if (Notification.permission === 'denied') {
        notifyStatus.textContent = 'Notifications are blocked in your browser settings.';
    } else {
        notifyStatus.textContent = '';
        notifyBtn.hidden = false;
    }
}

notifyBtn.addEventListener('click', async () => {
    await Notification.requestPermission();
    showNotifyState();
});

showNotifyState();

tick();
setInterval(tick, 1000);

// "Pick end time" popup
const endAt = document.getElementById('end-at');
const endChip = document.getElementById('end-chip');
const endChipText = document.getElementById('end-chip-text');
const endOpen = document.getElementById('end-open');
const dialog = document.getElementById('end-dialog');
const picker = document.getElementById('end-picker');
const endError = document.getElementById('end-error');
const durationFields = document.querySelectorAll('.add .dur');

// datetime-local inputs want "YYYY-MM-DDTHH:MM" in local time
const toLocalInput = d =>
    d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
    'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());

function showEndChoice() {
    const chosen = endAt.value !== '';
    endChip.hidden = !chosen;
    durationFields.forEach(f => f.hidden = chosen);
    endOpen.textContent = chosen ? 'Change end time' : 'Pick end time';
    if (chosen) endChipText.textContent = 'Ends ' + formatDate(Number(endAt.value) * 1000);
}

endOpen.addEventListener('click', () => {
    const now = new Date(Date.now() + offset);
    picker.min = toLocalInput(now);
    if (endAt.value) {
        picker.value = toLocalInput(new Date(Number(endAt.value) * 1000));
    } else {
        const inAnHour = new Date(now.getTime() + 3600000);
        inAnHour.setMinutes(0, 0, 0);
        picker.value = toLocalInput(inAnHour);
    }
    endError.textContent = '';
    dialog.showModal();
    picker.focus();
});

document.getElementById('end-confirm').addEventListener('click', () => {
    if (!picker.value) { endError.textContent = 'Choose a date and time.'; return; }
    const ms = new Date(picker.value).getTime();
    if (ms <= Date.now() + offset) { endError.textContent = 'Choose a time in the future.'; return; }
    endAt.value = Math.floor(ms / 1000);
    showEndChoice();
    dialog.close();
});

document.getElementById('end-cancel').addEventListener('click', () => dialog.close());

document.getElementById('end-clear').addEventListener('click', () => {
    endAt.value = '';
    showEndChoice();
    endOpen.focus();
});

showEndChoice();

// Delete asks "Sure?" first: first click arms the button, second click deletes.
// Listeners sit on the document so they keep working after the list is refreshed.
function disarm(form) {
    const del = form.querySelector('.danger');
    del.classList.remove('armed');
    del.textContent = 'Delete';
    form.querySelector('.cancel').hidden = true;
}

document.addEventListener('submit', event => {
    const form = event.target.closest('.delete-form');
    if (!form) return;
    const del = form.querySelector('.danger');
    if (del.classList.contains('armed')) return; // second click: delete
    event.preventDefault();
    del.classList.add('armed');
    del.textContent = 'Sure?';
    form.querySelector('.cancel').hidden = false;
});

document.addEventListener('click', event => {
    const cancel = event.target.closest('.delete-form .cancel');
    if (!cancel) return;
    const form = cancel.closest('.delete-form');
    disarm(form);
    form.querySelector('.danger').focus();
});

// Check the server once a minute for timers added, restarted or deleted elsewhere.
// Only the list is swapped, so anything you're typing in the add form is kept.
async function refreshList() {
    const list = document.getElementById('timer-list');
    // Don't pull the rug out while someone is confirming a delete
    if (list.querySelector('.armed')) return;
    try {
        const res = await fetch(location.pathname, { cache: 'no-store' });
        if (!res.ok) return;
        const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
        const fresh = doc.getElementById('timer-list');
        if (!fresh || fresh.dataset.version === list.dataset.version) return;
        if (list.querySelector('.armed')) return;
        list.replaceWith(document.importNode(fresh, true));
        tick();
    } catch (err) {
        // Server unreachable for a moment; try again next minute
    }
}

setInterval(refreshList, 60000);
</script>
</body>
</html>
