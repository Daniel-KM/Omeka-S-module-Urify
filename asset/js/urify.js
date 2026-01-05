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
            const urlAction = button.closest('[data-url-urify]')?.dataset.urlUrify;
            if (!urlAction) {
                return;
            }

            const updateMode = document.querySelector('input[name="update_mode"]:checked')?.value || 'single';
            const mapperSelect = document.querySelector('select[name="update_mapper"]');
            const updateMapper = mapperSelect?.value || '';
            const mapperLabel = mapperSelect?.selectedOptions[0]?.textContent || '';

            const value = button.closest('.urify-value-uris').querySelector('.value-to-urify')?.textContent;
            if (!value || value.trim() === '') {
                return;
            }

            const uri = button.parentElement.querySelector('.uri-label a')?.href || '';
            const label = button.parentElement.querySelector('.uri-label a')?.textContent || '';
            const property = button.closest('[data-property-term]')?.dataset.propertyTerm || '';

            const msgContainer = button.closest('[data-msg-apply]');
            const messageTemplate = msgContainer?.dataset.msgApply || '';
            const message = messageTemplate
                .replace(/{value}/g, value)
                .replace(/{uri}/g, uri)
                .replace(/{label}/g, label)
                .replace(/{property}/g, property);

            // Single mode: use prompt dialog as before.
            if (updateMode === 'single') {
                CommonDialog.dialogPrompt({
                    message: message,
                    defaultValue: label,
                }).then(confirmedLabel => {
                    if (!confirmedLabel && confirmedLabel !== '') {
                        return;
                    }
                    applyUriToValues(button, urlAction, property, value, uri, confirmedLabel, updateMode, updateMapper);
                });
                return;
            }

            // Batch mode: check that mapper is set.
            if (!updateMapper) {
                const msgMapperRequired = msgContainer?.dataset.msgMapperRequired
                    || Omeka.jsTranslate('A mapper must be selected to update the matching resources.');
                CommonDialog.dialogAlert({ message: msgMapperRequired });
                return;
            }

            // Batch mode with mapper: show confirmation dialog.
            const msgConfirmBatch = msgContainer?.dataset.msgConfirmBatch
                || Omeka.jsTranslate('Apply the mapper {mapper} to all resources with values fetched from uri {uri}?');
            const confirmMessage = msgConfirmBatch
                .replace(/{mapper}/g, mapperLabel)
                .replace(/{uri}/g, uri);

            CommonDialog.dialogConfirm({ message: confirmMessage })
                .then(confirmed => {
                    if (!confirmed) {
                        return;
                    }
                    applyUriToValues(button, urlAction, property, value, uri, label, updateMode, updateMapper);
                });
        });

        /**
         * Send the request to apply URI to values.
         */
        function applyUriToValues(button, urlAction, property, value, uri, label, updateMode, updateMapper) {
            CommonDialog.spinnerEnable(button);
            fetch(urlAction, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    property: property,
                    value: value.trim(),
                    uri: uri,
                    label: label,
                    update_mode: updateMode,
                    update_mapper: updateMapper,
                }),
            })
            .then(response => response.json())
            .then(data => CommonDialog.jSendResponse(data))
            .catch(error => CommonDialog.jSendFail(error))
            .finally(() => CommonDialog.spinnerDisable(button));
        }

    });
})();
