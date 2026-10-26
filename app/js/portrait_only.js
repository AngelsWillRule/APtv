/* Mobile orientation guard shared by AP, APtv and login. */
(function () {
    'use strict';
    const notice = document.getElementById('openapPortraitNotice');
    if (!notice) return;
    const landscape = window.matchMedia('(orientation: landscape)');
    const compact = window.matchMedia('(max-width: 991.98px), (max-width: 1280px) and (max-height: 600px)');
    let blocked = false;
    let leavingFullscreen = false;

    function aptvPlayerIsFullscreen() {
        const fullscreenElement = document.fullscreenElement || document.webkitFullscreenElement;
        const player = document.querySelector('[data-openap-aptv-player]');
        if (player && fullscreenElement &&
            (fullscreenElement === player || player.contains(fullscreenElement))) return true;
        const video = player?.querySelector('[data-openap-aptv-video]');
        return Boolean(video?.webkitDisplayingFullscreen);
    }

    function aptvWallIsFullscreen() {
        const fullscreenElement = document.fullscreenElement || document.webkitFullscreenElement;
        const wall = document.querySelector('[data-openap-wall]');
        return Boolean(wall && fullscreenElement &&
            (fullscreenElement === wall || wall.contains(fullscreenElement)));
    }

    function update() {
        blocked = landscape.matches && compact.matches &&
            !aptvPlayerIsFullscreen() && !aptvWallIsFullscreen();
        document.documentElement.classList.toggle('openap-portrait-required', blocked);
        if (!blocked) {
            if (notice.open) notice.close();
            return;
        }
        document.querySelectorAll('video, audio').forEach(media => {
            if (!media.paused) media.pause();
            // Safari's native video fullscreen is separate from the DOM API.
            if (media.webkitDisplayingFullscreen && media.webkitExitFullscreen) media.webkitExitFullscreen();
        });
        if (document.fullscreenElement && !leavingFullscreen) {
            leavingFullscreen = true;
            document.exitFullscreen().catch(() => {}).finally(() => {
                leavingFullscreen = false;
                if (blocked && !notice.open) notice.showModal();
            });
        }
        if (!notice.open) notice.showModal();
    }

    notice.addEventListener('cancel', event => event.preventDefault());
    document.addEventListener('play', event => {
        if (blocked && typeof event.target.pause === 'function') event.target.pause();
    }, true);
    [landscape, compact].forEach(query => query.addEventListener('change', update));
    document.addEventListener('fullscreenchange', update);
    window.addEventListener('pageshow', update);
    update();
})();
