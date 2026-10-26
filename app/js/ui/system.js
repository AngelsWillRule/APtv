import { set_theme } from "../helpers.js";

export function initSystem() {
    console.info("OpenAP System module initialized");

    document.querySelectorAll('[data-openap-copy-path]').forEach(function(button) {
        button.addEventListener('click', function() {
            var path = button.getAttribute('data-openap-copy-path') || '';
            var label = button.querySelector('span');
            var copy = navigator.clipboard && window.isSecureContext
                ? navigator.clipboard.writeText(path)
                : new Promise(function(resolve, reject) {
                    var handler = function(event) {
                        event.clipboardData.setData('text/plain', path);
                        event.preventDefault();
                    };
                    document.addEventListener('copy', handler, { once: true });
                    document.execCommand('copy') ? resolve() : reject(new Error('Copy failed'));
                });

            copy.then(function() {
                button.classList.add('is-copied');
                if (label) label.textContent = 'Copied';
                window.setTimeout(function() {
                    button.classList.remove('is-copied');
                    if (label) label.textContent = 'Copy path';
                }, 1600);
            }).catch(function() {
                if (label) label.textContent = 'Copy failed';
                window.setTimeout(function() {
                    if (label) label.textContent = 'Copy path';
                }, 1600);
            });
        });
    });

    $('#theme-select').on('change', function() {
        var selectedThemeName = $("#theme-select").val();

        if (selectedThemeName) {
            set_theme(selectedThemeName);
        }
    });
}
