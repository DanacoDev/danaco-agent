(function () {
    var navToggle = document.querySelector('[data-nav-toggle]');
    var nav = document.querySelector('[data-nav]');
    if (navToggle && nav) {
        navToggle.addEventListener('click', function () {
            var open = nav.classList.toggle('is-open');
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    var sideToggle = document.querySelector('[data-side-toggle]');
    var side = document.querySelector('[data-side]');
    if (sideToggle && side) {
        sideToggle.addEventListener('click', function () {
            var open = side.classList.toggle('is-open');
            sideToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        side.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                side.classList.remove('is-open');
                sideToggle.setAttribute('aria-expanded', 'false');
            });
        });
    }

    var headings = document.querySelectorAll('.docs-main h2[id]');
    var sideLinks = document.querySelectorAll('[data-side] a[href^="#"]');
    if (headings.length && sideLinks.length && 'IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                entry.target.dataset.visible = entry.isIntersecting ? '1' : '0';
            });
            var current = '';
            headings.forEach(function (heading) {
                if (!current && heading.dataset.visible === '1') {
                    current = heading.id;
                }
            });
            sideLinks.forEach(function (link) {
                var on = link.getAttribute('href') === '#' + current;
                link.classList.toggle('is-current', on);
            });
        }, { rootMargin: '-88px 0px -60% 0px', threshold: 0 });
        headings.forEach(function (heading) {
            observer.observe(heading);
        });
    }

    var input = document.getElementById('docs-search');
    var list = document.getElementById('docs-search-list');
    var dataEl = document.getElementById('docs-index');
    if (!input || !list || !dataEl) {
        return;
    }
    var items = [];
    try {
        items = JSON.parse(dataEl.textContent || '[]');
    } catch (error) {
        items = [];
    }
    var active = -1;
    var shown = [];

    function hide() {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        active = -1;
        shown = [];
    }

    function paint() {
        var links = list.querySelectorAll('a');
        links.forEach(function (link, index) {
            link.classList.toggle('is-active', index === active);
        });
        if (links[active]) {
            links[active].scrollIntoView({ block: 'nearest' });
        }
    }

    function show(query) {
        var q = query.trim().toLowerCase();
        list.innerHTML = '';
        active = -1;
        shown = [];
        if (!q) {
            hide();
            return;
        }
        shown = items.filter(function (item) {
            return (item.page + ' ' + item.title + ' ' + item.text).toLowerCase().indexOf(q) !== -1;
        }).slice(0, 8);
        if (!shown.length) {
            var empty = document.createElement('li');
            empty.className = 'search-empty';
            empty.textContent = 'No matches';
            list.appendChild(empty);
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            return;
        }
        shown.forEach(function (hit) {
            var li = document.createElement('li');
            var link = document.createElement('a');
            link.href = hit.url;
            var page = document.createElement('span');
            page.className = 'search-page';
            page.textContent = hit.page;
            var title = document.createElement('strong');
            title.textContent = hit.title;
            var text = document.createElement('span');
            text.className = 'search-text';
            text.textContent = hit.text;
            link.appendChild(page);
            link.appendChild(title);
            link.appendChild(text);
            li.appendChild(link);
            list.appendChild(li);
        });
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }

    input.addEventListener('input', function () {
        show(input.value);
    });
    input.addEventListener('keydown', function (event) {
        var links = list.querySelectorAll('a');
        if (event.key === 'Escape') {
            hide();
            return;
        }
        if (!links.length) {
            return;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            active = Math.min(active + 1, links.length - 1);
            paint();
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            active = Math.max(active - 1, 0);
            paint();
        } else if (event.key === 'Enter' && active >= 0 && links[active]) {
            event.preventDefault();
            window.location.href = links[active].href;
        }
    });
    document.addEventListener('click', function (event) {
        if (!event.target.closest('.search')) {
            hide();
        }
    });
})();
