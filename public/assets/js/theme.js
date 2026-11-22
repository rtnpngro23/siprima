(function () {
    // Light Mode Only: clear any previous theme preference so cached browsers reset cleanly
    try {
        window.localStorage.removeItem('siprima_theme');
    } catch (e) {}
    document.documentElement.setAttribute('data-theme', 'light');
})();
