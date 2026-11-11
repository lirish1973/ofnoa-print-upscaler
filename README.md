# Ofnoa Print Upscaler

WordPress plugin for print shops: turns customer artwork and photos into print-ready 4K files.

- **Upscale** – Real-ESRGAN through a fallback chain (free.ai → deAPI → your own PC → Replicate → Lanczos).
- **Prompt-guided enhancement** – optional deAPI Image Edit step before the upscale. In the default
  "by image type" mode Claude classifies each file and only photographs of people are enhanced;
  graphics and logos are upscaled only.
- **Proofing** – five Claude agents (two blind transcriptions, visual comparison, spelling check,
  chief proofreader) plus a deterministic pixel-fidelity check. Spelling mistakes are reported, never fixed.
- **Output** – 300 DPI PDF and full-resolution PNG, e-mailed to the shop (never to the customer).
- **Dashboard** – "שדרוג מהיר": upload, live progress, before/after, downloads, created-files list
  with delete. Available in wp-admin, through a secret staff link, or with `[ofnoa_upscaler_dashboard]`.
- **Customer form** – `[ofnoa_upscaler]` shortcode or the Elementor widget.

## Install

Download the ZIP from the latest [release](https://github.com/lirish1973/ofnoa-print-upscaler/releases)
and upload it in **Plugins → Add New → Upload**. API keys are set in **שדרוג גרפיקה → הגדרות**
(or as `wp-config.php` constants: `OFP_FREEAI_KEY`, `OFP_DEAPI_KEY`, `OFP_REPLICATE_TOKEN`, `OFP_ANTHROPIC_KEY`).
No keys are stored in this repository.

## Updates

The plugin polls `plugin-updates.json` on `main` once an hour. To ship a version:

1. Bump `Version:` and `OFP_VERSION` in `ofnoa-print-upscaler.php` (and add a section to `readme.txt`).
2. Push to `main`.

The **Build release ZIP** workflow packages the plugin, publishes a GitHub Release `vX.Y.Z` with the ZIP
attached and rewrites `plugin-updates.json`. Sites then see a normal plugin update, and install it
on their own when "עדכון אוטומטי" is on (settings → עדכונים).

Requires WordPress 6.0+, PHP 7.4+ with GD (Imagick optional, for PDF input).
