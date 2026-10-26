/*!
    * Start Bootstrap - SB Admin v7.0.7 (https://startbootstrap.com/template/sb-admin)
    * Copyright 2013-2023 Start Bootstrap
    * Licensed under MIT (https://github.com/StartBootstrap/startbootstrap-sb-admin/blob/master/LICENSE)
    */
    // 
// Scripts
// 

window.addEventListener('DOMContentLoaded', event => {

    // Toggle the side navigation
    const sidebarToggle = document.body.querySelector('#sidebarToggle');
    if (sidebarToggle) {
        const compactLandscapeQuery = window.matchMedia('(max-width: 1138px) and (max-height: 712px), (max-width: 1180px) and (max-height: 820px), (max-width: 1210px) and (max-height: 834px), (max-width: 1280px) and (max-height: 800px)');
        const mobileSidebarQuery = window.matchMedia('(max-width: 991.98px), (max-width: 1138px) and (max-height: 712px), (max-width: 1180px) and (max-height: 820px), (max-width: 1210px) and (max-height: 834px), (max-width: 1280px) and (max-height: 800px)');
        const mobileSidebarKey = 'openap|mobile-sidebar-open';
        const applyMobileSidebarState = () => {
            if (!mobileSidebarQuery.matches) {
                document.documentElement.classList.remove('openap-mobile-sidebar-open');
                return;
            }
            const restoreOpenState = !compactLandscapeQuery.matches && localStorage.getItem(mobileSidebarKey) === 'true';
            document.body.classList.toggle('sb-sidenav-toggled', restoreOpenState);
            if (compactLandscapeQuery.matches) {
                localStorage.setItem(mobileSidebarKey, 'false');
            }
            document.documentElement.classList.remove('openap-mobile-sidebar-open');
        };

        applyMobileSidebarState();
        sidebarToggle.addEventListener('click', event => {
            event.preventDefault();
            document.body.classList.toggle('sb-sidenav-toggled');
            if (mobileSidebarQuery.matches) {
                localStorage.setItem(
                    mobileSidebarKey,
                    document.body.classList.contains('sb-sidenav-toggled') ? 'true' : 'false'
                );
            }
        });

        const closeMobileSidebar = () => {
            if (!mobileSidebarQuery.matches) {
                return;
            }
            document.body.classList.remove('sb-sidenav-toggled');
            document.documentElement.classList.remove('openap-mobile-sidebar-open');
            try {
                localStorage.setItem(mobileSidebarKey, 'false');
            } catch (error) {
                // The visual close must still work when storage is unavailable.
            }
        };

        mobileSidebarQuery.addEventListener('change', event => {
            if (event.matches) {
                applyMobileSidebarState();
            } else {
                document.body.classList.remove('sb-sidenav-toggled');
            }
        });
    }

});
