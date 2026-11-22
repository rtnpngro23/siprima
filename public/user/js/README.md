Short notes:

- All page-specific JS files have been moved to `user/js/`.
- The JS files in `user/` are shims that load `js/global.js` first and then the real script in `user/js/`.
- Put shared JS utilities in `user/js/global.js`.

This keeps existing HTML/PHP references unchanged while centralizing code.
