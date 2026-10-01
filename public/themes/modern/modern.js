document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var root = document.documentElement;
    var themeButton = document.getElementById('ui-theme-toggle');
    var media = window.matchMedia('(prefers-color-scheme: dark)');
    function isDark() {
        var choice = root.dataset.uiTheme;
        return choice ? choice === 'dark' : media.matches;
    }
    function syncTheme() {
        if (themeButton) {
            themeButton.setAttribute('aria-pressed', String(isDark()));
            themeButton.setAttribute('aria-label', isDark() ? '切换浅色模式' : '切换深色模式');
            themeButton.title = themeButton.getAttribute('aria-label');
        }
    }
    syncTheme();
    if (themeButton) themeButton.addEventListener('click', function () {
        root.dataset.uiTheme = isDark() ? 'light' : 'dark';
        try { localStorage.setItem('ui-theme', root.dataset.uiTheme); } catch (e) {}
        syncTheme();
    });
    media.addEventListener('change', syncTheme);
    var drawer = document.getElementById('mobile-nav');
    var toggle = document.getElementById('menu-toggle');
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && drawer && drawer.classList.contains('open')) {
            drawer.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.focus();
        }
    });
    var search = document.getElementById('catalog-search');
    var cards = Array.from(document.querySelectorAll('[data-catalog-item]'));
    var empty = document.getElementById('catalog-empty');
    var status = document.getElementById('catalog-search-status');
    if (search) search.addEventListener('input', function () {
        var query = search.value.trim().toLocaleLowerCase();
        var visible = 0;
        cards.forEach(function (card) {
            card.hidden = !card.dataset.search.toLocaleLowerCase().includes(query);
            if (!card.hidden) visible++;
        });
        if (empty) empty.hidden = visible > 0;
        if (status) status.textContent = '找到 ' + visible + ' 件商品';
    });
});