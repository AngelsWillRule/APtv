import { getCSRFToken } from "../helpers.js";
import { load80211wSelect } from "../ui/hostapd.js";

export function initHostapd_ajax() {
    console.info("OpenAP Hostapd ajax module initialized");

    let openapFrequencies = [];

    function compatibleOpenApWidths(channel, is5GHz, availableChannels) {
        const available = new Set(availableChannels.map(Number));
        const containsBlock = block => block[0] === channel && block.every(item => available.has(item));
        const widths = [20];

        if (!is5GHz) {
            if (channel <= 7 && available.has(channel + 4)) widths.push(40);
            return widths;
        }

        const blocks40 = [
            [36, 40], [44, 48], [52, 56], [60, 64],
            [100, 104], [108, 112], [116, 120], [124, 128],
            [132, 136], [140, 144], [149, 153], [157, 161], [165, 169]
        ];
        const blocks80 = [
            [36, 40, 44, 48], [52, 56, 60, 64],
            [100, 104, 108, 112], [116, 120, 124, 128],
            [132, 136, 140, 144], [149, 153, 157, 161]
        ];

        if (blocks40.some(containsBlock)) widths.push(40);
        if (blocks80.some(containsBlock)) widths.push(80);
        return widths;
    }

    function populateOpenApControls(selectedChannel = null, resetBandDefaults = false, optimizeWidth = false) {
        const bandSelect = $('#cbxopenapband');
        if (bandSelect.length === 0) return;

        const band = bandSelect.val();
        const is5GHz = band === '5';
        const hwMode = is5GHz ? 'ac' : 'n';
        const modeLabel = is5GHz ? '802.11ac' : '802.11n';
        const widthSelect = $('#cbxopenapwidth');
        $('#cbxhwmode').val(hwMode);
        $('#txtopenapmode').val(modeLabel);

        const channels = openapFrequencies.filter(item => {
            const mhz = Number(item.MHz);
            return is5GHz ? mhz >= 5000 && mhz < 6000 : mhz >= 2400 && mhz < 2500;
        });
        const channelSelect = $('#cbxchannel');
        const saveButton = $('#btnSaveHostapd');
        channelSelect.empty();

        if (channels.length === 0) {
            channelSelect.append($('<option></option>').attr('value', '').text('No supported channels'));
            channelSelect.prop('disabled', true);
            saveButton.prop('disabled', true);
            $('#openap-channel-status').text(`No ${band} GHz channels are available on this adapter.`);
            return;
        }

        channels.forEach(item => {
            channelSelect.append(
                $('<option></option>')
                    .attr('value', item.Channel)
                    .text(`${item.Channel} (${item.MHz} MHz)`)
            );
        });
        const available = channels.map(item => Number(item.Channel));
        let requested = Number(selectedChannel);
        if (!available.includes(requested)) requested = available[0];
        channelSelect.val(String(requested));

        const widths = compatibleOpenApWidths(requested, is5GHz, available);
        let selectedWidth = Number(widthSelect.attr('data-current-width') || 20);
        if (resetBandDefaults || optimizeWidth || !widths.includes(selectedWidth)) {
            selectedWidth = 20;
        }
        widthSelect.empty();
        widths.forEach(width => {
            widthSelect.append($('<option></option>').attr('value', width).text(`${width} MHz`));
        });
        widthSelect.val(String(selectedWidth));
        widthSelect.attr('data-current-width', selectedWidth);

        channelSelect.prop('disabled', false);
        saveButton.prop('disabled', false);
        $('#openap-channel-status').text(
            `Available ${band} GHz channels: ${available.join(', ')}. Compatible widths for channel ${requested}: ${widths.join(', ')} MHz. Default: 20 MHz.`
        );
    }

    function loadOpenApFrequencies(selectedChannel = null) {
        const iface = $('#cbxinterface').val();
        const csrfToken = getCSRFToken();
        $('#openap-channel-status').text('Loading supported channels...');
        $.post('ajax/networking/get_frequencies.php', {
            interface: iface,
            csrf_token: csrfToken
        }).done(function(response) {
            try {
                openapFrequencies = typeof response === 'object' ? response : JSON.parse(response);
                if (!Array.isArray(openapFrequencies)) throw new Error('Invalid frequency response');
                populateOpenApControls(selectedChannel, false);
            } catch (error) {
                openapFrequencies = [];
                populateOpenApControls(null, false);
                $('#openap-channel-status').text('Unable to read supported channels. Refresh the page and try again.');
            }
        }).fail(function() {
            openapFrequencies = [];
            populateOpenApControls(null, false);
            $('#openap-channel-status').text('Unable to load supported channels from OpenAP.');
        });
    }

    $(document).on("click", "#js-clearhostapd-log", function(e) {
        var csrfToken = getCSRFToken();
        $.post('ajax/logging/clearlog.php?', {
                'logfile':'/tmp/hostapd.log',
                'csrf_token': csrfToken
            }, function(data) {
                let jsonData = JSON.parse(data);
                $("#hostapd-log").val("");
            });
    });

    $('#cbxinterface').on('change', function () {
        const iface = $(this).val();
        const csrfToken = getCSRFToken();
        $.post('ajax/networking/get_hostapd_config.php', {
            interface: iface,
            csrf_token: csrfToken
        }, function (data) {
            if (data.error) {
                return;
            }
            if (data.ssid) $('#txtssid').val(data.ssid);
            if (data.hw_mode) $('#cbxhwmode').val(data.hw_mode);
            if (data.channel) $('#cbxchannel').val(data.channel);
            if (data.wpa) $('#cbxwpa').val(data.wpa);
            if (data.wpa_pairwise) $('#cbxwpapairwise').val(data.wpa_pairwise);
            if (data.country_code) $('#cbxcountries').val(data.country_code);
            if (data.wpa_passphrase) $('#txtwpapassphrase').val(data.wpa_passphrase);

            load80211wSelect();
        });
    });

    /* Sets hardware mode tooltip text for selected interface
    */
    function setHardwareModeTooltip() {
        var iface = $('#cbxinterface').val();
        var hwmodeText = '';
        var csrfToken = getCSRFToken();
        // Explanatory text if 802.11ac is disabled
        if ($('#cbxhwmode').find('option[value="ac"]').prop('disabled') == true ) {
            var hwmodeText = $('#hwmode').attr('data-tooltip');
        }
        $.post('ajax/networking/get_nl80211_band.php?', {
                'interface': iface,
                'csrf_token': csrfToken
            }, function(data) {
                var responseText = JSON.parse(data);
                $('#tiphwmode').attr('data-original-title', responseText + '\n' + hwmodeText );
            });
    }

    /*
    Sets the wirelss channel select options based on frequencies reported by iw.

    See: https://git.kernel.org/pub/scm/linux/kernel/git/sforshee/wireless-regdb.git
    Also: https://en.wikipedia.org/wiki/List_of_WLAN_channels
    */
    function loadChannelSelect(selected) {
        var iface = $('#cbxinterface').val();
        var hwmodeText = '';
        var csrfToken = getCSRFToken();

        // update hardware mode tooltip
        setHardwareModeTooltip();

        $.post('ajax/networking/get_frequencies.php',{'interface': iface, 'csrf_token': csrfToken, 'selected': selected},function(response){
            var hw_mode = $('#cbxhwmode').val();
            var country_code = $('#cbxcountries').val();
            var channel_select = $('#cbxchannel');
            var btn_save = $('#btnSaveHostapd');
            var data = JSON.parse(response);
            var selectableChannels = [];

            // Map selected hw_mode to available channels
            if (hw_mode === 'a') {
                selectableChannels = data.filter(item => item.MHz.toString().startsWith('5'));
            } else if (hw_mode === 'ac') {
                selectableChannels = data.filter(item => item.MHz.toString().startsWith('5'));
            } else if (hw_mode === 'ax') {
                selectableChannels = data.filter(item => item.MHz.toString().startsWith('5'));
            } else if (hw_mode === 'be') {
                selectableChannels = data.filter(item => item.MHz.toString().startsWith('5'));
            } else {
                // hw_mode 'b', 'g', or default to 2.4GHz
                selectableChannels = data.filter(item => item.MHz.toString().startsWith('24'));
            }

            // If selected channel doeesn't exist in allowed channels, set default or null (unsupported)
            if (!selectableChannels.find(item => item.Channel === selected)) {
                if (selectableChannels.length === 0) {
                    selectableChannels[0] = { Channel: null };
                } else {
                    let defaultChannel = selectableChannels[0].Channel;
                    selected = defaultChannel;
                }
            }

            // Set channel select with available values
            channel_select.empty();
            if (selectableChannels[0].Channel === null) {
                channel_select.append($("<option></option>").attr("value", "").text("---"));
                channel_select.prop("disabled", true);
                btn_save.prop("disabled", true);
            } else {
                channel_select.prop("disabled", false);
                btn_save.prop("disabled", false);
                $.each(selectableChannels, function(key,value) {
                    channel_select.append($("<option></option>").attr("value", value.Channel).text(value.Channel));
                });
                channel_select.val(selected);
            }
        });
    }

    // Retrieves the 'channel' value specified in hostapd.conf
    function getChannel() {
        if ($('#cbxopenapband').length) {
            const current = Number($('#cbxchannel').attr('data-current-channel')) || null;
            loadOpenApFrequencies(current);
            return;
        }
        var iface = $('#cbxinterface').val();
        var csrfToken = getCSRFToken();

        $.post('ajax/networking/get_channel.php', {
            'interface': iface,
            'csrf_token': csrfToken
        }, function (data) {
            let jsonData;
            try {
                jsonData = typeof data === 'object' ? data : JSON.parse(data);
            } catch (e) {
                return;
            }
            if (jsonData.error) {
                console.warn('Channel error:', jsonData.error);
                loadChannelSelect(null); // fallback
                return;
            }
            loadChannelSelect(jsonData);
        });
    }
    globalThis.getChannel = getChannel;

    $(document).off('change.openapBand', '#cbxopenapband');
    $(document).on('change.openapBand', '#cbxopenapband', function() {
        populateOpenApControls(null, true, true);
    });

    $(document).off('change.openapChannel', '#cbxchannel');
    $(document).on('change.openapChannel', '#cbxchannel', function() {
        populateOpenApControls(Number($(this).val()), false, true);
    });

    $(document).off('change.openapWidth', '#cbxopenapwidth');
    $(document).on('change.openapWidth', '#cbxopenapwidth', function() {
        $(this).attr('data-current-width', Number($(this).val()));
    });

    getChannel();
    if ($('#cbxopenapband').length === 0) {
        setHardwareModeTooltip();
    }
}
