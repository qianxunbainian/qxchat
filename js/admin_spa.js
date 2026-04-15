/**
 * 后台：侧栏不刷新，仅替换 #admin-spa-content（GET 导航）。
 * 表单 POST、新窗口、按住修饰键仍为整页跳转。
 */
(function () {
    'use strict';

    var mainEl = document.getElementById('admin-main');
    var spaRoot = document.getElementById('admin-spa-content');
    if (!mainEl || !spaRoot) return;

    function isAdminPageUrl(url) {
        try {
            var u = typeof url === 'string' ? new URL(url, window.location.href) : url;
            if (u.origin !== window.location.origin) return false;
            var path = u.pathname || '';
            var base = path.replace(/^.*\//, '');
            return (
                base === 'admin.php' ||
                base === 'admin_users.php' ||
                base === 'admin_user_edit.php' ||
                base === 'admin_room_messages.php'
            );
        } catch (e) {
            return false;
        }
    }

    function fetchPartial(url) {
        var u = new URL(url, window.location.href);
        u.searchParams.set('partial', '1');
        return fetch(u.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'text/html', 'X-Requested-With': 'fetch' },
            cache: 'no-store',
        }).then(function (res) {
            if (res.redirected && res.url && res.url.indexOf('login.php') !== -1) {
                window.location.href = res.url;
                return null;
            }
            if (!res.ok) throw new Error(res.statusText || String(res.status));
            return res.text();
        });
    }

    function applyFragment(html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var next = doc.getElementById('admin-spa-content');
        if (!next) {
            return false;
        }
        var title = next.getAttribute('data-page-title');
        var navKey = next.getAttribute('data-admin-nav-active');
        spaRoot.outerHTML = next.outerHTML;
        spaRoot = document.getElementById('admin-spa-content');
        if (!spaRoot) return false;
        if (title) document.title = title;
        if (navKey) updateNavActive(navKey);
        mainEl.scrollTop = 0;
        try {
            window.dispatchEvent(new CustomEvent('adminspa:navigated', { detail: { navKey: navKey } }));
        } catch (e) { /* ignore */ }
        return true;
    }

    function updateNavActive(navKey) {
        var nav = document.getElementById('admin-nav');
        if (!nav) return;
        nav.querySelectorAll('.admin-nav-link').forEach(function (a) {
            var k = a.getAttribute('data-admin-nav');
            var on = k === navKey;
            a.classList.toggle('is-active', on);
            if (on) a.setAttribute('aria-current', 'page');
            else a.removeAttribute('aria-current');
        });
    }

    function navigate(url, pushState) {
        if (pushState === undefined) pushState = true;
        spaRoot = document.getElementById('admin-spa-content');
        if (!spaRoot) {
            window.location.href = url;
            return;
        }
        var cleanUrl = new URL(url, window.location.href);
        cleanUrl.searchParams.delete('partial');
        spaRoot.classList.add('is-admin-spa-loading');
        fetchPartial(url)
            .then(function (html) {
                if (html === null) return;
                if (!applyFragment(html)) {
                    window.location.href = cleanUrl.pathname + cleanUrl.search + cleanUrl.hash;
                    return;
                }
                if (pushState) {
                    history.pushState({ adminSpa: true }, '', cleanUrl.pathname + cleanUrl.search + cleanUrl.hash);
                }
            })
            .catch(function () {
                window.location.href = cleanUrl.pathname + cleanUrl.search + cleanUrl.hash;
            })
            .finally(function () {
                var el = document.getElementById('admin-spa-content');
                if (el) el.classList.remove('is-admin-spa-loading');
            });
    }

    function onClick(e) {
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a || a.getAttribute('target') === '_blank') return;
        if (e.defaultPrevented) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        if (a.hasAttribute('download')) return;
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#') return;
        if (!isAdminPageUrl(a.href)) return;
        e.preventDefault();
        navigate(a.href, true);
        var root = document.querySelector('.admin-app');
        if (root && root.classList.contains('admin-sidebar-open')) {
            root.classList.remove('admin-sidebar-open');
            document.body.style.overflow = '';
            var backdrop = document.getElementById('admin-sidebar-backdrop');
            if (backdrop) {
                backdrop.hidden = true;
                backdrop.setAttribute('aria-hidden', 'true');
            }
            var toggle = document.getElementById('admin-menu-toggle');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        }
    }

    document.addEventListener('click', onClick, true);

    window.addEventListener('popstate', function () {
        if (!isAdminPageUrl(window.location.href)) return;
        navigate(window.location.href, false);
    });

    if (history.replaceState && window.location.pathname.indexOf('admin') !== -1) {
        try {
            history.replaceState({ adminSpa: true }, '', window.location.pathname + window.location.search + window.location.hash);
        } catch (e) { /* ignore */ }
    }
})();
