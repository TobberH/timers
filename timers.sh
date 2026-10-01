#!/usr/bin/env bash
# Print the timers from timers.json as a table.
# Usage: ./timers.sh [path/to/timers.json]   (defaults to timers.json next to this script)
set -euo pipefail

file="${1:-$(dirname "$0")/timers.json}"

if ! command -v jq >/dev/null; then
    echo "This script needs jq. Install it with: sudo apt install jq  (Arch: sudo pacman -S jq, macOS: brew install jq)" >&2
    exit 1
fi
if [[ ! -f "$file" ]]; then
    echo "No timers file found at $file" >&2
    exit 1
fi
if [[ "$(jq 'length' "$file")" == "0" ]]; then
    echo "No timers."
    exit 0
fi

now=$(date +%s)

# Colors only when printing to a terminal
if [[ -t 1 ]]; then use_color=1; red=$'\e[31m'; reset=$'\e[0m'; else use_color=0; red=""; reset=""; fi

# Turn a timer's hex color (#RRGGBB) into a terminal color code.
# Uses exact 24-bit color when the terminal supports it, otherwise the nearest of 256 colors.
name_color() {
    (( use_color )) || return 0
    local hex=$1
    [[ $hex =~ ^#[0-9A-Fa-f]{6}$ ]] || hex="#F2B134"
    local r=$(( 16#${hex:1:2} )) g=$(( 16#${hex:3:2} )) b=$(( 16#${hex:5:2} ))
    if [[ ${COLORTERM:-} == truecolor || ${COLORTERM:-} == 24bit ]]; then
        printf '\e[38;2;%d;%d;%dm' "$r" "$g" "$b"
    else
        to_cube() { local v=$1; if (( v < 48 )); then echo 0; elif (( v < 115 )); then echo 1; else echo $(( (v - 35) / 40 )); fi; }
        printf '\e[38;5;%dm' $(( 16 + 36 * $(to_cube "$r") + 6 * $(to_cube "$g") + $(to_cube "$b") ))
    fi
}

# Format seconds as "2d 03:14:07", negative when the timer is past due
time_left() {
    local s=$1 sign=""
    if (( s < 0 )); then sign="-"; s=$(( -s )); fi
    local d=$(( s / 86400 )) h=$(( s % 86400 / 3600 )) m=$(( s % 3600 / 60 )) sec=$(( s % 60 ))
    if (( d > 0 )); then
        printf '%s%dd %02d:%02d:%02d' "$sign" "$d" "$h" "$m" "$sec"
    else
        printf '%s%02d:%02d:%02d' "$sign" "$h" "$m" "$sec"
    fi
}

# Unix timestamp to readable local date (GNU date on Linux, BSD date on macOS)
end_date() {
    date -d "@$1" '+%a %d %b %Y %H:%M' 2>/dev/null || date -r "$1" '+%a %d %b %Y %H:%M'
}

printf '%-40s  %-21s  %s\n' "NAME" "ENDS" "TIME LEFT"
printf '%-40s  %-21s  %s\n' "----" "----" "---------"

jq -r 'sort_by(.start + .duration)[] | [(.start + .duration), (.color // "#F2B134"), .name] | @tsv' "$file" |
while IFS=$'\t' read -r end hex name; do
    left=$(( end - now ))
    color=""; (( left <= 0 )) && color=$red
    namecolor=$(name_color "$hex")
    printf '%s%-40s%s  %-21s  %s%s%s\n' "$namecolor" "${name:0:40}" "$reset" "$(end_date "$end")" "$color" "$(time_left "$left")" "$reset"
done
