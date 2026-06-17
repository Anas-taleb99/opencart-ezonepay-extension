(function () {
    var pollTimer = null;
    var retries = 0;
    var maxRetries = 120;
    var paymentId = 0;

    function getElements(root) {
        if (!root) {
            return null;
        }

        var elements = {
            root: root,
            button: root.querySelector('#button-confirm'),
            panel: root.querySelector('[data-ezonepay-panel]'),
            qr: root.querySelector('[data-ezonepay-qr]'),
            link: root.querySelector('[data-ezonepay-link]'),
            reference: root.querySelector('[data-ezonepay-reference]'),
            amount: root.querySelector('[data-ezonepay-amount]'),
            status: root.querySelector('[data-ezonepay-status]'),
            error: root.querySelector('[data-ezonepay-error]')
        };

        if (!elements.button || !elements.panel || !elements.qr || !elements.link || !elements.reference || !elements.amount || !elements.status || !elements.error) {
            return null;
        }

        return elements;
    }

    function showError(elements, message) {
        elements.error.textContent = message;
        elements.error.classList.remove('d-none');
    }

    function clearError(elements) {
        elements.error.textContent = '';
        elements.error.classList.add('d-none');
    }

    function setLoading(elements, loading) {
        elements.button.disabled = loading;
        elements.button.classList.toggle('disabled', loading);
    }

    function setQr(elements, link) {
        var wrapper = elements.qr.closest('.col-md-auto');

        if (!elements.root.dataset.qrBase) {
            if (wrapper) {
                wrapper.classList.add('d-none');
            }

            elements.qr.removeAttribute('src');
            return;
        }

        if (wrapper) {
            wrapper.classList.remove('d-none');
        }

        elements.qr.src = elements.root.dataset.qrBase + encodeURIComponent(link);
    }

    function post(url, data) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: new URLSearchParams(data || {})
        }).then(function (response) {
            return response.json();
        });
    }

    function poll(elements) {
        retries += 1;

        post(elements.root.dataset.statusUrl, { ezonepay_payment_id: paymentId }).then(function (json) {
            if (json.error) {
                throw new Error(json.error);
            }

            if (json.redirect) {
                elements.status.textContent = elements.root.dataset.textPaid;
                window.clearInterval(pollTimer);
                window.location.href = json.redirect;
                return;
            }

            if (retries >= maxRetries) {
                window.clearInterval(pollTimer);
                showError(elements, elements.root.dataset.errorTimeout);
                setLoading(elements, false);
            }
        }).catch(function (caught) {
            window.clearInterval(pollTimer);
            showError(elements, caught.message || String(caught));
            setLoading(elements, false);
        });
    }

    function bind(root) {
        var elements = getElements(root);

        if (!elements || elements.root.dataset.ezonepayBound === '1') {
            return;
        }

        elements.root.dataset.ezonepayBound = '1';

        elements.button.addEventListener('click', function (event) {
            event.preventDefault();
            clearError(elements);
            setLoading(elements, true);

            post(elements.root.dataset.createUrl).then(function (json) {
                if (json.error) {
                    throw new Error(json.error);
                }

                if (json.redirect) {
                    window.location.href = json.redirect;
                    return;
                }

                paymentId = json.ezonepay_payment_id;
                elements.reference.textContent = json.order_reference;
                elements.amount.textContent = json.amount;
                elements.link.href = json.link;
                setQr(elements, json.link);
                elements.panel.classList.remove('d-none');
                elements.status.textContent = elements.root.dataset.textWaiting;
                retries = 0;

                if (pollTimer) {
                    window.clearInterval(pollTimer);
                }

                pollTimer = window.setInterval(function () {
                    poll(elements);
                }, 5000);
                poll(elements);
            }).catch(function (caught) {
                showError(elements, caught.message || String(caught));
                setLoading(elements, false);
            });
        });
    }

    document.querySelectorAll('#ezonepay-payment').forEach(bind);
}());
