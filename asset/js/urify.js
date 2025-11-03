'use strict';

/**
 * Use common-dialog.js.
 *
 * @see Access, Comment, ContactUs, Contribute, Generate, Guest, Resa, SearchHistory, Selection, TwoFactorAuth, Urify.
 */

(function() {
    $(document).ready(function() {

        $('.apply-uri-to-values').on('click', function(event) {
            event.preventDefault();
            const button = event.currentTarget;
            const urlAction = button.closest('[data-url-urify]').dataset.urlUrify;
            if (!urlAction) {
                return;
            }

            const value = button.closest('.urify-value-uris').querySelector('.value-to-urify')?.textContent;
            if (!value || value.trim() === '') {
                return;
            }

            const uri = button.parentElement.querySelector('.uri-label a')?.href;
            const label = button.parentElement.querySelector('.uri-label a')?.textContent;
            const property = button.closest('[data-property-term]').dataset.propertyTerm;

            const msgContainer = button.closest('[data-msg-apply]');
            const messageTemplate = msgContainer?.dataset.msgApply || '';
            const message = messageTemplate
                .replace(/{value}/g, value)
                .replace(/{uri}/g, uri)
                .replace(/{label}/g, label)
                .replace(/{property}/g, property);

            CommonDialog.dialogPrompt({
                message: message,
                defaultValue: label,
            }).then(confirmedLabel => {
                if (confirmedLabel) {
                    CommonDialog.spinnerEnable(button);
                    fetch(urlAction, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            property: property,
                            value: value.trim(),
                            uri: uri,
                            label: confirmedLabel,
                        }),
                    })
                    .then(response => response.json())
                    .then(data => CommonDialog.jSendResponse(data))
                    .catch(error => CommonDialog.jSendFail(error))
                    .finally(() => CommonDialog.spinnerDisable(button));
                }
            });
        })

    });
})();
