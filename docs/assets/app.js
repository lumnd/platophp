(() => {
    const sidebar = document.querySelector('.sidebar');
    const main = document.querySelector('.main');
    const button = document.querySelector('.menu-button');
    const backdrop = document.querySelector('.sidebar-backdrop');
    const search = document.querySelector('#nav-search');
    const links = [...document.querySelectorAll('.navigation a')];
    let request = null;

    const setMenu = (open) => {
        sidebar?.classList.toggle('open', open);
        button?.setAttribute('aria-expanded', String(open));
        if (backdrop) backdrop.hidden = !open;
    };

    const showActiveLink = () => {
        const active = document.querySelector('.navigation a.active');
        if (!sidebar || !active) return;

        const sidebarRect = sidebar.getBoundingClientRect();
        const activeRect = active.getBoundingClientRect();
        if (activeRect.top < sidebarRect.top || activeRect.bottom > sidebarRect.bottom) {
            active.scrollIntoView({ block: 'center' });
        }
    };

    const setActiveLink = (url) => {
        const page = url.pathname.split('/').pop();
        links.forEach((link) => {
            const active = new URL(link.href).pathname.endsWith(`/${page}`);
            link.classList.toggle('active', active);
            if (active) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });
        showActiveLink();
    };

    const loadPage = async (url, addHistory) => {
        request?.abort();
        const currentRequest = new AbortController();
        request = currentRequest;
        main?.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(url.href, { signal: currentRequest.signal });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const article = page.querySelector('.document');
            const pager = page.querySelector('.pager');
                if (!article || !pager) throw new Error('Incomplete documentation page');

            document.querySelector('.document')?.replaceWith(article);
            document.querySelector('.pager')?.replaceWith(pager);
            document.title = page.title;

            const description = page.querySelector('meta[name="description"]')?.content;
            if (description) {
                document.querySelector('meta[name="description"]')?.setAttribute('content', description);
            }

            if (addHistory) {
                history.pushState(null, '', `${url.pathname}${url.search}${url.hash}`);
            }

            setActiveLink(url);
            setMenu(false);
            window.scrollTo(0, 0);
        } catch (error) {
            if (error.name !== 'AbortError') {
                window.location.assign(url.href);
            }
        } finally {
            if (request === currentRequest) {
                main?.removeAttribute('aria-busy');
            }
        }
    };

    button?.addEventListener('click', () => setMenu(!sidebar?.classList.contains('open')));
    backdrop?.addEventListener('click', () => setMenu(false));

    links.forEach((link) => {
        link.addEventListener('click', (event) => {
            if (
                location.protocol === 'file:' ||
                event.button !== 0 ||
                event.metaKey ||
                event.ctrlKey ||
                event.shiftKey ||
                event.altKey
            ) {
                return;
            }

            event.preventDefault();
            loadPage(new URL(link.href), true);
        });
    });

    search?.addEventListener('input', () => {
        const locale = document.documentElement.lang || undefined;
        const keyword = search.value.trim().toLocaleLowerCase(locale);
        links.forEach((link) => {
            const title = (link.dataset.title || '').toLocaleLowerCase(locale);
            link.hidden = keyword !== '' && !title.includes(keyword);
        });
    });

    window.addEventListener('popstate', () => loadPage(new URL(location.href), false));
    showActiveLink();
})();
