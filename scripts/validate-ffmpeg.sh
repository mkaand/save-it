#!/usr/bin/env bash
set -euo pipefail

work_dir="$(mktemp -d)"
cleanup() {
    rm -rf -- "$work_dir"
}
trap cleanup EXIT

ffmpeg -version >/dev/null

ffmpeg -nostdin -hide_banner -loglevel error -y \
    -f lavfi -i "color=c=black:s=320x180:d=1:r=25" \
    -an -c:v libx264 -pix_fmt yuv420p "$work_dir/video.mp4"
ffmpeg -nostdin -hide_banner -loglevel error -y \
    -f lavfi -i "sine=frequency=440:duration=1" \
    -vn -c:a aac -b:a 128k "$work_dir/audio.m4a"
ffmpeg -nostdin -hide_banner -loglevel error -y \
    -i "$work_dir/video.mp4" -i "$work_dir/audio.m4a" \
    -map 0:v:0 -map 1:a:0 -c copy -movflags +faststart "$work_dir/merged.mp4"

ffprobe -v error -select_streams v:0 -show_entries stream=codec_name \
    -of default=noprint_wrappers=1:nokey=1 "$work_dir/merged.mp4" | grep -qx "h264"
ffprobe -v error -select_streams a:0 -show_entries stream=codec_name \
    -of default=noprint_wrappers=1:nokey=1 "$work_dir/merged.mp4" | grep -qx "aac"

for bitrate in 128 192 256 320; do
    ffmpeg -nostdin -hide_banner -loglevel error -y \
        -i "$work_dir/audio.m4a" -vn -codec:a libmp3lame -b:a "${bitrate}k" \
        "$work_dir/audio-${bitrate}.mp3"
    test -s "$work_dir/audio-${bitrate}.mp3"
done

printf 'FFmpeg merge and MP3 validation passed.\n'
