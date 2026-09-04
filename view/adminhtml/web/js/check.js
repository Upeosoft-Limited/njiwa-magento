/**
 * The two buttons under Njiwa's connection settings.
 *
 * Both ask the server, because the API key is stored encrypted and the browser
 * has no business holding it. The answer is put on the page as text and never
 * as HTML: what comes back can contain a number's label from an account we did
 * not type, and a settings page is not a place to run somebody else's markup.
 */
define(['jquery', 'mage/translate'], function ($, $t) {
    'use strict';

    /**
     * @param {jQuery} $result Where the answer goes.
     * @param {Boolean} ok Whether Njiwa was happy.
     * @param {String} text Plain text, one paragraph per blank line.
     */
    function show($result, ok, text) {
        var $box = $('<div/>').addClass('message ' + (ok ? 'message-success success' : 'message-error error'));

        $.each(String(text).split('\n'), function (index, line) {
            if (index > 0) {
                $box.append($('<br/>'));
            }
            $box.append(document.createTextNode(line));
        });

        $result.empty().append($box);
    }

    return function (config, element) {
        $(element).on('click', function () {
            var $button = $(this),
                $result = $('#' + config.resultId),
                data = {
                    form_key: config.formKey
                };

            if (config.numberId) {
                data.number = $.trim($('#' + config.numberId).val() || '');
            }

            // Pressing twice must not send twice. The server refuses a second
            // test message within a few seconds as well, because a disabled
            // button is a courtesy and not a guarantee.
            $button.prop('disabled', true);
            $result.text($t('Asking Njiwa...'));

            $.post(config.url, data).done(function (response) {
                if (response && typeof response.message === 'string') {
                    show($result, !!response.success, response.message);
                } else {
                    show($result, false, $t('Magento answered something this page did not understand. Look in var/log/njiwa.log.'));
                }
            }).fail(function () {
                show($result, false, $t('Magento could not be reached to run the check. Reload the page and try again.'));
            }).always(function () {
                $button.prop('disabled', false);
            });
        });
    };
});
