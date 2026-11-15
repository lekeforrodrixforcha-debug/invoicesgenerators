document.addEventListener('DOMContentLoaded', function () {
    const invoiceLoadingScreen = document.querySelector('[data-invoice-loading-screen]');
    const invoiceLoadingMessage = document.querySelector('[data-invoice-loading-message]');
    document.querySelectorAll('form[data-invoice-submit]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented || form.dataset.submitting === 'true' || !invoiceLoadingScreen) {
                if (form.dataset.submitting === 'true') {
                    event.preventDefault();
                }
                return;
            }

            form.dataset.submitting = 'true';
            if (invoiceLoadingMessage) {
                invoiceLoadingMessage.textContent = form.dataset.loadingMessage || 'Saving your invoice…';
            }
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
                button.disabled = true;
            });
            invoiceLoadingScreen.hidden = false;
        });
    });

    document.querySelectorAll('[data-password-toggle]').forEach(function (toggle) {
        const passwordInputId = toggle.getAttribute('aria-controls');
        const passwordInput = passwordInputId ? document.getElementById(passwordInputId) : null;
        const label = toggle.querySelector('[data-password-toggle-label]');
        if (!(passwordInput instanceof HTMLInputElement) || !label) {
            return;
        }

        toggle.addEventListener('click', function () {
            const showPassword = passwordInput.type === 'password';
            passwordInput.type = showPassword ? 'text' : 'password';
            toggle.setAttribute('aria-pressed', String(showPassword));
            toggle.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
            label.textContent = showPassword ? 'Hide' : 'Show';
        });
    });

    document.addEventListener('click', function (event) {
        const copyButton = event.target.closest('[data-copy-customer-link]');
        if (!copyButton) {
            return;
        }

        const notice = copyButton.closest('.customer-link-notice');
        const link = notice && notice.querySelector('[data-customer-link]');
        const status = notice && notice.querySelector('[data-copy-customer-link-status]');
        if (!link || !status) {
            return;
        }

        const copyValue = new URL(link.getAttribute('href'), window.location.href).href;
        const fallbackCopy = function () {
            const input = document.createElement('textarea');
            input.value = copyValue;
            input.setAttribute('readonly', '');
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.select();
            const copied = document.execCommand('copy');
            input.remove();
            if (!copied) {
                throw new Error('Copy was blocked by the browser.');
            }
        };
        const showCopied = function () {
            status.textContent = 'Copied. You can now paste it into a message.';
        };
        const showCopyError = function () {
            status.textContent = 'Copy failed. Open the link and copy its address from your browser.';
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(copyValue).then(showCopied).catch(function () {
                try {
                    fallbackCopy();
                    showCopied();
                } catch (error) {
                    showCopyError();
                }
            });
        } else {
            try {
                fallbackCopy();
                showCopied();
            } catch (error) {
                showCopyError();
            }
        }
    });

    const assistantDestinations = {
        dashboard: { label: 'Open overview', url: 'dashboard.php' },
        invoices: { label: 'Open invoices', url: 'invoices.php' },
        new_invoice: { label: 'Create an invoice', url: 'templates.php' },
        templates: { label: 'Browse templates', url: 'templates.php' },
        customers: { label: 'Open customers', url: 'customers.php' },
        products: { label: 'Open products', url: 'products.php' },
        settings: { label: 'Open settings', url: 'settings.php' }
    };
    const sidebar = document.querySelector('.sidebar');
    const sidebarMenuButton = document.querySelector('.sidebar-menu-button');

    if (sidebar && sidebarMenuButton) {
        sidebarMenuButton.addEventListener('click', function () {
            const isOpen = sidebar.classList.toggle('is-open');
            sidebarMenuButton.setAttribute('aria-expanded', String(isOpen));
        });

        sidebar.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
                sidebar.classList.remove('is-open');
                sidebarMenuButton.setAttribute('aria-expanded', 'false');
                sidebarMenuButton.focus();
            }
        });

        sidebar.querySelectorAll('.side-nav a').forEach(function (link) {
            link.addEventListener('click', function () {
                sidebar.classList.remove('is-open');
                sidebarMenuButton.setAttribute('aria-expanded', 'false');
            });
        });
    }

    const revealTargets = document.querySelectorAll('[data-reveal]');
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (revealTargets.length && 'IntersectionObserver' in window && !prefersReducedMotion) {
        document.documentElement.classList.add('motion-ready');
        const revealObserver = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12 });

        revealTargets.forEach(function (target) {
            revealObserver.observe(target);
        });
    }

    const globalAiPanel = document.querySelector('[data-global-ai-panel]');
    if (globalAiPanel) {
        const toggle = document.querySelector('[data-global-ai-toggle]');
        const closeButton = globalAiPanel.querySelector('[data-global-ai-close]');
        const messages = globalAiPanel.querySelector('[data-global-ai-messages]');
        const input = globalAiPanel.querySelector('[data-global-ai-input]');
        const sendButton = globalAiPanel.querySelector('[data-global-ai-send]');
        const status = globalAiPanel.querySelector('[data-global-ai-status]');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const chatHistory = [];

        function setOpen(isOpen) {
            globalAiPanel.hidden = !isOpen;
            toggle.setAttribute('aria-expanded', String(isOpen));
            toggle.setAttribute('aria-label', isOpen ? 'Close Gen AI assistant' : 'Open Gen AI assistant');
            toggle.title = isOpen ? 'Close Gen AI assistant' : 'Chat with Gen AI';
            if (isOpen) {
                input.focus();
            }
        }

        function appendMessage(role, text, destination) {
            const message = document.createElement('div');
            message.className = 'ai-chat-message ai-chat-message-' + role;
            const author = document.createElement('strong');
            const content = document.createElement('p');
            author.textContent = role === 'user' ? 'You' : 'Gen AI';
            content.textContent = text;
            message.append(author, content);

            const target = assistantDestinations[destination];
            if (role === 'assistant' && target) {
                const link = document.createElement('a');
                link.className = 'ai-assistant-navigation-link';
                link.href = target.url;
                link.textContent = target.label;
                message.appendChild(link);
            }

            messages.appendChild(message);
            messages.scrollTop = messages.scrollHeight;
        }

        async function sendMessage() {
            const message = input.value.trim();
            if (!message || sendButton.disabled) {
                return;
            }
            if (!csrfToken) {
                status.textContent = 'Refresh the page to renew your session.';
                status.classList.add('is-error');
                return;
            }

            input.value = '';
            appendMessage('user', message);
            chatHistory.push({ role: 'user', text: message });
            sendButton.disabled = true;
            input.disabled = true;
            sendButton.textContent = 'Thinking…';
            status.textContent = 'Gen AI is thinking…';
            status.classList.remove('is-error');
            const slowRequestNotice = window.setTimeout(function () {
                status.textContent = 'This request can take up to 30 seconds. Keep this page open.';
            }, 6000);

            try {
                const recentMessages = chatHistory.slice(-8);
                if (recentMessages[0] && recentMessages[0].role === 'assistant') {
                    recentMessages.shift();
                }
                const response = await fetch('ai_invoice_assist.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        csrf: csrfToken,
                        action: 'chat',
                        input: { page: globalAiPanel.dataset.aiPage, messages: recentMessages }
                    })
                });
                const result = await response.json();
                if (!response.ok) {
                    throw new Error(result.error || 'Gen AI could not answer. Please try again.');
                }

                chatHistory.push({ role: 'assistant', text: result.text });
                appendMessage('assistant', result.text, result.destination);
                status.textContent = '';
            } catch (error) {
                chatHistory.pop();
                status.textContent = error instanceof Error ? error.message : 'Gen AI could not answer. Please try again.';
                status.classList.add('is-error');
            } finally {
                window.clearTimeout(slowRequestNotice);
                sendButton.disabled = false;
                input.disabled = false;
                sendButton.textContent = 'Send';
                input.focus();
            }
        }

        toggle.addEventListener('click', function () {
            setOpen(globalAiPanel.hidden);
        });
        closeButton.addEventListener('click', function () {
            setOpen(false);
            toggle.focus();
        });
        globalAiPanel.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                setOpen(false);
                toggle.focus();
            }
        });
        sendButton.addEventListener('click', sendMessage);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendMessage();
            }
        });
    }

    const invoiceTable = document.querySelector('#invoice-items-table');
    const addRowBtn = document.querySelector('[data-add-row]');
    const visualInvoice = document.querySelector('.visual-invoice-document');
    const visualCurrency = document.querySelector('[data-visual-currency]');
    const visualTemplate = document.querySelector('[data-visual-template]');
    const visualTemplateValue = document.querySelector('[data-visual-template-value]');
    const visualStatus = document.querySelector('[data-visual-status]');
    const visualIssuerMark = document.querySelector('[data-issuer-mark]');
    const businessLogoInput = document.querySelector('[data-business-logo-input]');
    const businessLogoPreview = document.querySelector('[data-logo-preview]');
    const businessLogoFallback = document.querySelector('[data-logo-fallback]');
    const businessLogoError = document.querySelector('[data-logo-upload-error]');
    const totals = {
        subtotal: document.querySelector('#subtotal-value'),
        discount: document.querySelector('#discount-value'),
        tax: document.querySelector('#tax-value'),
        total: document.querySelector('#total-value')
    };

    function formatMoney(amount) {
        if (visualInvoice) {
            const currency = visualCurrency ? visualCurrency.value : 'USD';
            return currency + ' ' + amount.toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        return '$' + amount.toFixed(2);
    }

    function calculateRows() {
        if (!invoiceTable || !totals.subtotal) {
            return;
        }

        let subtotal = 0;
        let discount = 0;
        let tax = 0;

        const rows = invoiceTable.querySelectorAll('tr.item-row');
        rows.forEach(function (row) {
            const quantity = parseFloat(row.querySelector('[data-field="quantity"]').value || 0);
            const unitPrice = parseFloat(row.querySelector('[data-field="unit_price"]').value || 0);
            const lineDiscount = parseFloat(row.querySelector('[data-field="discount"]').value || 0);
            const taxRate = parseFloat(row.querySelector('[data-field="tax_rate"]').value || 0);

            const lineSubtotal = quantity * unitPrice;
            const discountedSubtotal = lineSubtotal - lineDiscount;
            const lineTax = (discountedSubtotal * taxRate) / 100;

            subtotal += lineSubtotal;
            discount += lineDiscount;
            tax += lineTax;

            const lineTotal = (discountedSubtotal + lineTax);
            const lineTotalField = row.querySelector('[data-field="line_total"]');
            if (lineTotalField) {
                lineTotalField.value = lineTotal.toFixed(2);
            }
            const lineTotalDisplay = row.querySelector('[data-line-total-display]');
            if (lineTotalDisplay) {
                lineTotalDisplay.textContent = formatMoney(lineTotal);
            }
        });

        const total = subtotal - discount + tax;
        totals.subtotal.textContent = formatMoney(subtotal);
        totals.discount.textContent = formatMoney(discount);
        totals.tax.textContent = formatMoney(tax);
        totals.total.textContent = formatMoney(total);
    }

    if (visualTemplate && visualTemplateValue && visualInvoice) {
        visualTemplate.addEventListener('change', function () {
            visualInvoice.classList.forEach(function (className) {
                if (className.indexOf('template-') === 0) {
                    visualInvoice.classList.remove(className);
                }
            });
            visualInvoice.classList.add('template-' + visualTemplate.value);
            visualTemplateValue.value = visualTemplate.value;
        });
    }

    document.querySelectorAll('[data-visual-sync]').forEach(function (field) {
        field.addEventListener('input', function () {
            const fieldName = field.dataset.visualSync;
            const value = field.value;
            document.querySelectorAll('[data-visual-sync="' + fieldName + '"]').forEach(function (syncedField) {
                if (syncedField !== field) {
                    syncedField.value = value;
                }
            });
            const submittedValue = document.querySelector('[data-visual-sync-value="' + fieldName + '"]');
            if (submittedValue) {
                submittedValue.value = value;
            }
            if (fieldName === 'issuer_name' && visualIssuerMark) {
                const logoFallback = visualIssuerMark.querySelector('[data-logo-fallback]');
                if (logoFallback && (!businessLogoPreview || businessLogoPreview.hidden)) {
                    logoFallback.textContent = value.trim().charAt(0).toUpperCase() || 'I';
                }
            }
        });
    });

    const statusSelect = document.querySelector('#status');
    if (statusSelect && visualStatus) {
        statusSelect.addEventListener('change', function () {
            visualStatus.textContent = statusSelect.value;
        });
    }

    if (visualCurrency) {
        visualCurrency.addEventListener('change', calculateRows);
    }

    if (businessLogoInput && businessLogoPreview && visualIssuerMark) {
        let previewObjectUrl = null;
        const uploadLabel = businessLogoInput.closest('label');

        businessLogoInput.addEventListener('change', function () {
            const file = businessLogoInput.files && businessLogoInput.files[0];
            if (!file) {
                return;
            }

            const allowedTypes = ['image/png', 'image/jpeg', 'image/webp'];
            if (!allowedTypes.includes(file.type) || file.size > 3 * 1024 * 1024) {
                businessLogoInput.value = '';
                const error = !allowedTypes.includes(file.type)
                    ? 'Choose a PNG, JPG or WebP image.'
                    : 'Logo images must be 3 MB or smaller.';
                if (businessLogoError) {
                    businessLogoError.textContent = error;
                }
                return;
            }

            if (businessLogoError) {
                businessLogoError.textContent = '';
            }
            if (previewObjectUrl) {
                URL.revokeObjectURL(previewObjectUrl);
            }
            previewObjectUrl = URL.createObjectURL(file);
            businessLogoPreview.src = previewObjectUrl;
            businessLogoPreview.hidden = false;
            if (businessLogoFallback) {
                businessLogoFallback.hidden = true;
            }
            visualIssuerMark.classList.add('has-image');
            if (uploadLabel) {
                uploadLabel.dataset.originalLabel = uploadLabel.dataset.originalLabel || uploadLabel.childNodes[0].textContent;
                uploadLabel.childNodes[0].textContent = 'Change logo';
            }
        });
    }

    document.querySelectorAll('[data-ai-assist]').forEach(function (aiAssist) {
        const aiDescription = aiAssist.querySelector('[data-ai-description]');
        const aiMode = aiAssist.querySelector('[data-ai-mode]');
        const aiPromptLabel = aiAssist.querySelector('[data-ai-prompt-label]');
        const aiCompose = aiAssist.querySelector('[data-ai-compose]');
        const aiTargetWrap = aiAssist.querySelector('[data-ai-target-wrap]');
        const aiTarget = aiAssist.querySelector('[data-ai-target]');
        const aiGenerateButton = aiAssist.querySelector('[data-ai-generate]');
        const aiApplyButton = aiAssist.querySelector('[data-ai-apply]');
        const aiDismissButton = aiAssist.querySelector('[data-ai-dismiss]');
        const aiStatus = aiAssist.querySelector('[data-ai-status]');
        const aiPreview = aiAssist.querySelector('[data-ai-preview]');
        const aiItemsContainer = aiAssist.querySelector('[data-ai-items-container]');
        const aiItemsList = aiAssist.querySelector('[data-ai-items]');
        const aiDetails = aiAssist.querySelector('[data-ai-details]');
        const aiGeneratedText = aiAssist.querySelector('[data-ai-generated-text]');
        const aiSubject = aiAssist.querySelector('[data-ai-subject]');
        const aiResultText = aiAssist.querySelector('[data-ai-result-text]');
        const form = aiAssist.closest('form');
        const csrfInput = form ? form.querySelector('input[name="csrf"]') : document.querySelector('meta[name="csrf-token"]');
        const widgetAction = aiAssist.dataset.aiWidget || '';
        let currentResult = null;
        let currentAction = widgetAction;

        if (!aiGenerateButton || !aiStatus || !aiPreview || !aiApplyButton || !aiDismissButton) {
            return;
        }

        const aiChat = aiAssist.querySelector('[data-ai-chat]');
        if (aiChat) {
            const aiChatToggle = form ? form.querySelector('[data-ai-chat-toggle]') : document.querySelector('[data-ai-chat-toggle]');
            const aiChatMessages = aiChat.querySelector('[data-ai-chat-messages]');
            const aiChatInput = aiChat.querySelector('[data-ai-chat-input]');
            const aiChatSend = aiChat.querySelector('[data-ai-chat-send]');
            const aiChatCreateInvoice = aiChat.querySelector('[data-ai-chat-create-invoice]');
            const aiChatStatus = aiChat.querySelector('[data-ai-chat-status]');
            const aiAssistTools = aiAssist.querySelector('.ai-assist-tools');
            const chatHistory = [];

            document.body.append(aiChatToggle, aiAssist);
            aiAssist.classList.add('ai-assist-floating');

            function setChatOpen(isOpen) {
                aiAssist.hidden = !isOpen;
                aiChatToggle.setAttribute('aria-expanded', String(isOpen));
                aiChatToggle.setAttribute('aria-label', isOpen ? 'Close Gen AI chat' : 'Open Gen AI chat');
                aiChatToggle.title = isOpen ? 'Close Gen AI chat' : 'Chat with Gen AI';
                if (isOpen) {
                    aiChatInput.focus();
                }
            }

            function appendChatMessage(role, text, destination) {
                const message = document.createElement('div');
                message.className = 'ai-chat-message ai-chat-message-' + role;
                const author = document.createElement('strong');
                const content = document.createElement('p');
                author.textContent = role === 'user' ? 'You' : 'Gen AI';
                content.textContent = text;
                message.appendChild(author);
                message.appendChild(content);
                const target = assistantDestinations[destination];
                if (role === 'assistant' && target) {
                    const link = document.createElement('a');
                    link.className = 'ai-assistant-navigation-link';
                    link.href = target.url;
                    link.textContent = target.label;
                    message.appendChild(link);
                }
                aiChatMessages.appendChild(message);
                aiChatMessages.scrollTop = aiChatMessages.scrollHeight;
            }

            async function sendChatMessage() {
                const message = aiChatInput.value.trim();
                if (!message || aiChatSend.disabled) {
                    return;
                }
                if (message.length > 2000) {
                    aiChatStatus.textContent = 'Chat messages are limited to 2,000 characters. Use Create invoice for longer quote text.';
                    aiChatStatus.classList.add('is-error');
                    return;
                }
                if (!csrfInput) {
                    aiChatStatus.textContent = 'Refresh the page to renew your session.';
                    aiChatStatus.classList.add('is-error');
                    return;
                }

                aiChatInput.value = '';
                appendChatMessage('user', message);
                chatHistory.push({ role: 'user', text: message });
                aiChatSend.disabled = true;
                aiChatInput.disabled = true;
                aiChatSend.textContent = 'Thinking…';
                aiChatStatus.textContent = 'Gen AI is thinking…';
                aiChatStatus.classList.remove('is-error');
                const slowRequestNotice = window.setTimeout(function () {
                    aiChatStatus.textContent = 'This request can take up to 30 seconds. Keep this page open.';
                }, 6000);

                try {
                    const recentMessages = chatHistory.slice(-8);
                    if (recentMessages[0] && recentMessages[0].role === 'assistant') {
                        recentMessages.shift();
                    }
                    const csrfToken = csrfInput instanceof HTMLInputElement
                        ? csrfInput.value
                        : csrfInput.getAttribute('content');
                    const response = await fetch('ai_invoice_assist.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            csrf: csrfToken,
                            action: 'chat',
                            input: { page: 'new_invoice', messages: recentMessages }
                        })
                    });
                    const result = await response.json();
                    if (!response.ok) {
                        throw new Error(result.error || 'Gen AI could not answer. Please try again.');
                    }

                    chatHistory.push({ role: 'assistant', text: result.text });
                    appendChatMessage('assistant', result.text, result.destination);
                    aiChatStatus.textContent = '';
                } catch (error) {
                    chatHistory.pop();
                    aiChatStatus.textContent = error instanceof Error ? error.message : 'Gen AI could not answer. Please try again.';
                    aiChatStatus.classList.add('is-error');
                } finally {
                    window.clearTimeout(slowRequestNotice);
                    aiChatSend.disabled = false;
                    aiChatInput.disabled = false;
                    aiChatSend.textContent = 'Send';
                    aiChatInput.focus();
                }
            }

            async function createInvoiceFromChat() {
                const sourceText = aiChatInput.value.trim();
                if (!sourceText) {
                    aiChatStatus.textContent = 'Paste the quote or invoice details you want Gen AI to extract.';
                    aiChatStatus.classList.add('is-error');
                    aiChatInput.focus();
                    return;
                }
                if (!aiMode || !aiDescription || !aiAssistTools) {
                    aiChatStatus.textContent = 'Invoice extraction is unavailable on this page.';
                    aiChatStatus.classList.add('is-error');
                    return;
                }

                aiChatStatus.textContent = '';
                aiChatStatus.classList.remove('is-error');
                aiChatCreateInvoice.disabled = true;
                aiChatCreateInvoice.textContent = 'Extracting…';
                aiAssistTools.open = true;
                aiMode.value = 'draft_invoice';
                aiMode.dispatchEvent(new Event('change', { bubbles: true }));
                aiDescription.value = sourceText;
                aiChatInput.value = '';

                try {
                    await generateAiResult();
                } finally {
                    aiChatCreateInvoice.disabled = false;
                    aiChatCreateInvoice.textContent = 'Create invoice';
                    aiChatInput.focus();
                }
            }

            aiChatToggle.addEventListener('click', function () {
                setChatOpen(aiAssist.hidden);
            });
            aiAssist.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    setChatOpen(false);
                    aiChatToggle.focus();
                }
            });
            aiChatSend.addEventListener('click', sendChatMessage);
            aiChatCreateInvoice.addEventListener('click', createInvoiceFromChat);
            aiChatInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    sendChatMessage();
                }
            });
        }

        const taskDetails = {
            draft_invoice: {
                label: 'Paste a quote, email, chat, or notes to extract invoice details',
                placeholder: 'Paste your quote or message here. Gen AI will extract the client, sender details, items, quantities, prices, dates, and terms when stated.',
                button: 'Extract invoice details',
                waiting: 'Gen AI is extracting invoice details…'
            },
            draft_items: {
                label: 'Describe the work for the line items',
                placeholder: 'For example: Design a logo for $600 and prepare two social media templates.',
                button: 'Draft line items',
                waiting: 'Gen AI is drafting line items…'
            },
            rewrite_description: {
                label: 'Enter a rough line item description',
                placeholder: 'For example: made new website pages and fixed phone version',
                button: 'Polish description',
                waiting: 'Gen AI is polishing the description…'
            },
            note: {
                label: 'Describe the tone or context for the note',
                placeholder: 'For example: Thank them for the launch project and invite them to reach out with questions.',
                button: 'Draft invoice note',
                waiting: 'Gen AI is drafting a note…'
            },
            terms: {
                label: 'Describe the payment arrangement',
                placeholder: 'For example: Payment is due within 14 days by bank transfer.',
                button: 'Draft payment terms',
                waiting: 'Gen AI is drafting payment terms…'
            },
            explain_totals: {
                label: '',
                placeholder: '',
                button: 'Explain invoice totals',
                waiting: 'Gen AI is explaining the current invoice totals…'
            }
        };

        function setAiStatus(message, isError) {
            aiStatus.textContent = message;
            aiStatus.classList.toggle('is-error', Boolean(isError));
        }

        function setHidden(element, hidden) {
            if (element) {
                element.hidden = hidden;
            }
        }

        function clearAiPreview() {
            currentResult = null;
            aiPreview.hidden = true;
            if (aiItemsList) {
                aiItemsList.replaceChildren();
            }
            if (aiDetails) {
                aiDetails.replaceChildren();
            }
            if (aiResultText) {
                aiResultText.textContent = '';
            }
            if (aiSubject) {
                aiSubject.textContent = '';
            }
            setHidden(aiItemsContainer, true);
            setHidden(aiDetails, true);
            setHidden(aiGeneratedText, true);
            setHidden(aiSubject, true);
        }

        function renderItems(items) {
            if (!aiItemsList || !Array.isArray(items)) {
                return;
            }
            aiItemsList.replaceChildren();
            items.forEach(function (item) {
                const listItem = document.createElement('li');
                const description = document.createElement('span');
                const amount = document.createElement('strong');
                description.textContent = item.description;
                amount.textContent = 'Quantity ' + item.quantity;
                if (Number(item.unit_price) > 0) {
                    amount.textContent += ' · ' + formatMoney(Number(item.unit_price)) + ' per unit';
                } else {
                    amount.textContent += ' · Rate not set';
                }
                if (Number(item.tax_rate) > 0) {
                    amount.textContent += ' · Tax ' + Number(item.tax_rate) + '%';
                }
                listItem.appendChild(amount);
                listItem.appendChild(description);
                aiItemsList.appendChild(listItem);
            });
            setHidden(aiItemsContainer, false);
        }

        function renderDetails(fields) {
            if (!aiDetails) {
                return;
            }
            aiDetails.replaceChildren();
            fields.forEach(function (field) {
                if (!field.value) {
                    return;
                }
                const term = document.createElement('dt');
                const value = document.createElement('dd');
                term.textContent = field.label;
                value.textContent = field.value;
                aiDetails.appendChild(term);
                aiDetails.appendChild(value);
            });
            setHidden(aiDetails, aiDetails.childElementCount === 0);
        }

        function lineItemRows() {
            return invoiceTable ? Array.from(invoiceTable.querySelectorAll('tr.item-row')) : [];
        }

        function refreshRewriteTargets(preserveSelection) {
            if (!aiTarget || !invoiceTable) {
                return;
            }
            const previousIndex = preserveSelection ? Number(aiTarget.value) : 0;
            aiTarget.replaceChildren();
            lineItemRows().forEach(function (row, index) {
                const description = row.querySelector('[data-field="description"]').value.trim();
                const option = document.createElement('option');
                option.value = String(index);
                option.textContent = description || 'Line item ' + (index + 1);
                aiTarget.appendChild(option);
            });
            if (aiTarget.options.length) {
                aiTarget.value = String(Math.min(previousIndex, aiTarget.options.length - 1));
            }
        }

        function updateTaskUi() {
            if (!aiMode || !aiDescription) {
                return;
            }
            const details = taskDetails[aiMode.value];
            if (!details) {
                return;
            }
            clearAiPreview();
            setAiStatus('', false);
            aiDescription.hidden = aiMode.value === 'explain_totals';
            setHidden(aiCompose, aiMode.value === 'explain_totals');
            setHidden(aiPromptLabel, aiMode.value === 'explain_totals');
            setHidden(aiTargetWrap, aiMode.value !== 'rewrite_description');
            aiDescription.placeholder = details.placeholder;
            aiGenerateButton.textContent = details.button;
            if (aiPromptLabel) {
                aiPromptLabel.textContent = details.label;
            }
            if (aiMode.value === 'rewrite_description') {
                refreshRewriteTargets(false);
                const row = lineItemRows()[Number(aiTarget.value) || 0];
                aiDescription.value = row ? row.querySelector('[data-field="description"]').value : '';
            } else if (aiMode.value === 'explain_totals') {
                aiDescription.value = '';
            }
        }

        function buildRequest() {
            if (widgetAction === 'reminder') {
                return {
                    action: 'draft_message',
                    input: { kind: 'reminder', invoice_id: Number(aiAssist.dataset.invoiceId) }
                };
            }
            if (widgetAction === 'summarize_business') {
                return { action: 'summarize_business', input: {} };
            }

            const action = aiMode.value;
            const input = { currency: visualCurrency ? visualCurrency.value : 'USD' };
            if (action === 'note' || action === 'terms') {
                if (!aiDescription.value.trim()) {
                    throw new Error('Add a short context before drafting this text.');
                }
                return { action: 'draft_message', input: { kind: action, context: aiDescription.value.trim() } };
            }
            if (action === 'rewrite_description') {
                if (!aiDescription.value.trim()) {
                    throw new Error('Enter a line item description to rewrite.');
                }
                input.text = aiDescription.value.trim();
            } else if (action === 'explain_totals') {
                input.items = lineItemRows().map(function (row) {
                    return {
                        description: row.querySelector('[data-field="description"]').value,
                        quantity: Number(row.querySelector('[data-field="quantity"]').value),
                        unit_price: Number(row.querySelector('[data-field="unit_price"]').value),
                        discount: Number(row.querySelector('[data-field="discount"]').value),
                        tax_rate: Number(row.querySelector('[data-field="tax_rate"]').value)
                    };
                });
            } else {
                if (!aiDescription.value.trim()) {
                    throw new Error('Describe the client and work before drafting an invoice.');
                }
                input.description = aiDescription.value.trim();
            }
            return { action: action, input: input };
        }

        function copyText(text) {
            function fallbackCopy() {
                const temporaryInput = document.createElement('textarea');
                temporaryInput.value = text;
                temporaryInput.style.position = 'fixed';
                temporaryInput.style.opacity = '0';
                document.body.appendChild(temporaryInput);
                try {
                    temporaryInput.select();
                    if (!document.execCommand('copy')) {
                        throw new Error('Copy was blocked by the browser.');
                    }
                    return Promise.resolve();
                } finally {
                    temporaryInput.remove();
                }
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(text).catch(fallbackCopy);
            }
            return fallbackCopy();
        }

        function applyInvoiceItems(items) {
            const tableBody = invoiceTable && invoiceTable.querySelector('tbody');
            const rowTemplate = document.querySelector('#invoice-item-row-template');
            if (!tableBody || !rowTemplate || !Array.isArray(items) || items.length === 0) {
                throw new Error('The invoice editor could not apply these line items.');
            }
            const rows = Array.from(tableBody.querySelectorAll('tr.item-row'));
            while (rows.length < items.length) {
                const newRow = rowTemplate.content.firstElementChild.cloneNode(true);
                tableBody.appendChild(newRow);
                rows.push(newRow);
            }
            rows.forEach(function (row, index) {
                if (index >= items.length) {
                    row.remove();
                    return;
                }
                const item = items[index];
                row.querySelector('[data-field="description"]').value = item.description;
                row.querySelector('[data-field="quantity"]').value = String(item.quantity);
                row.querySelector('[data-field="unit_price"]').value = String(item.unit_price);
                row.querySelector('[data-field="discount"]').value = String(item.discount);
                row.querySelector('[data-field="tax_rate"]').value = String(item.tax_rate);
                row.querySelectorAll('[data-field]').forEach(function (field) {
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                });
            });
            refreshRewriteTargets(true);
        }

        async function generateAiResult() {
            if (!csrfInput) {
                setAiStatus('Refresh the page to renew your AI session.', true);
                return;
            }
            let requestData;
            try {
                requestData = buildRequest();
            } catch (error) {
                setAiStatus(error instanceof Error ? error.message : 'Check the information and try again.', true);
                if (aiDescription && !aiDescription.hidden) {
                    aiDescription.focus();
                }
                return;
            }

            const task = aiMode ? taskDetails[aiMode.value] : null;
            const buttonLabel = aiGenerateButton.textContent;
            const csrfToken = csrfInput instanceof HTMLInputElement
                ? csrfInput.value
                : csrfInput.getAttribute('content');
            aiGenerateButton.disabled = true;
            aiAssist.setAttribute('aria-busy', 'true');
            aiGenerateButton.textContent = task ? task.waiting : 'Gen AI is working…';
            clearAiPreview();
            setAiStatus('Gen AI is preparing suggestions…', false);
            const slowRequestNotice = window.setTimeout(function () {
                setAiStatus('This request can take up to 30 seconds. Keep this page open.', false);
            }, 6000);

            try {
                const response = await fetch('ai_invoice_assist.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ csrf: csrfToken, action: requestData.action, input: requestData.input })
                });
                const result = await response.json();
                if (!response.ok) {
                    throw new Error(result.error || 'Gen AI could not complete this request.');
                }

                currentResult = result;
                currentAction = requestData.action;
                if (Array.isArray(result.items)) {
                    renderItems(result.items);
                }
                if (result.invoice) {
                    renderItems(result.invoice.items);
                    renderDetails([
                        { label: 'Invoice sender', value: result.invoice.issuer.name },
                        { label: 'Sender email', value: result.invoice.issuer.email },
                        { label: 'Sender phone', value: result.invoice.issuer.phone },
                        { label: 'Currency', value: result.invoice.currency },
                        { label: 'Client', value: result.invoice.client.name || result.invoice.client.company },
                        {
                            label: 'Company',
                            value: result.invoice.client.name && result.invoice.client.name !== result.invoice.client.company
                                ? result.invoice.client.company
                                : ''
                        },
                        { label: 'Email', value: result.invoice.client.email },
                        { label: 'Address', value: result.invoice.client.address },
                        { label: 'Phone', value: result.invoice.client.phone },
                        { label: 'Issue date', value: result.invoice.dates.issue_date },
                        { label: 'Due date', value: result.invoice.dates.due_date },
                        { label: 'Invoice note', value: result.invoice.notes },
                        { label: 'Payment terms', value: result.invoice.terms }
                    ]);
                }
                if (typeof result.text === 'string') {
                    if (aiResultText) {
                        aiResultText.textContent = result.text;
                    }
                    setHidden(aiGeneratedText, false);
                }
                if (typeof result.subject === 'string' && aiSubject) {
                    aiSubject.textContent = 'Subject: ' + result.subject;
                    setHidden(aiSubject, false);
                }
                aiApplyButton.textContent = requestData.action === 'draft_items'
                    ? 'Use these line items'
                    : requestData.action === 'draft_invoice'
                        ? 'Apply invoice draft'
                        : requestData.action === 'rewrite_description'
                            ? 'Use rewritten description'
                            : requestData.action === 'explain_totals' || requestData.action === 'summarize_business'
                                ? 'Copy summary'
                                : requestData.input.kind === 'reminder'
                                    ? 'Copy reminder'
                                    : 'Apply text';
                aiPreview.hidden = false;
                setAiStatus('Suggestions are ready. Review them before applying or copying.', false);
                aiPreview.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } catch (error) {
                setAiStatus(error instanceof Error ? error.message : 'Gen AI could not complete this request. Please try again.', true);
            } finally {
                window.clearTimeout(slowRequestNotice);
                aiAssist.removeAttribute('aria-busy');
                aiGenerateButton.disabled = false;
                aiGenerateButton.textContent = task ? task.button : buttonLabel;
            }
        }

        aiGenerateButton.addEventListener('click', generateAiResult);

        aiApplyButton.addEventListener('click', async function () {
            if (!currentResult) {
                setAiStatus('Generate a suggestion first.', true);
                return;
            }
            try {
                if (widgetAction === 'reminder' || widgetAction === 'summarize_business' || currentAction === 'explain_totals') {
                    const text = aiSubject && aiSubject.textContent
                        ? aiSubject.textContent + '\n\n' + (aiResultText ? aiResultText.textContent : '')
                        : (aiResultText ? aiResultText.textContent : '');
                    await copyText(text);
                    setAiStatus(widgetAction === 'reminder' ? 'Reminder copied. Review and send it yourself.' : 'Summary copied.', false);
                    return;
                }

                if (currentAction === 'draft_items') {
                    applyInvoiceItems(currentResult.items);
                    setAiStatus('Line items added. Review and edit the invoice before saving.', false);
                } else if (currentAction === 'draft_invoice') {
                    const invoiceDraft = currentResult.invoice;
                    applyInvoiceItems(invoiceDraft.items);
                    const fieldMap = {
                        issuer_name: invoiceDraft.issuer.name,
                        issuer_email: invoiceDraft.issuer.email,
                        issuer_phone: invoiceDraft.issuer.phone,
                        currency: invoiceDraft.currency,
                        customer_name: invoiceDraft.client.name || invoiceDraft.client.company,
                        recipient_company: invoiceDraft.client.name ? invoiceDraft.client.company : '',
                        recipient_email: invoiceDraft.client.email,
                        recipient_address: invoiceDraft.client.address,
                        recipient_phone: invoiceDraft.client.phone,
                        issue_date: invoiceDraft.dates.issue_date,
                        due_date: invoiceDraft.dates.due_date,
                        notes: invoiceDraft.notes,
                        terms: invoiceDraft.terms
                    };
                    Object.keys(fieldMap).forEach(function (fieldName) {
                        if (!fieldMap[fieldName]) {
                            return;
                        }
                        const fields = Array.from(form.querySelectorAll('[name="' + fieldName + '"]'));
                        if (fieldName === 'issuer_name' || fieldName === 'issuer_email') {
                            fields.push.apply(fields, Array.from(form.querySelectorAll('[data-visual-sync="' + fieldName + '"]')));
                        }
                        fields.forEach(function (field) {
                            field.value = fieldMap[fieldName];
                            field.dispatchEvent(new Event('input', { bubbles: true }));
                            field.dispatchEvent(new Event('change', { bubbles: true }));
                        });
                    });
                        setAiStatus(
                            !invoiceDraft.client.name && !invoiceDraft.client.company
                                ? 'Details extracted and applied. The source did not identify a bill-to client; enter the client before saving. Review quantities, rates and total.'
                                : 'Invoice details extracted and applied. Check the sender, client, quantities, rates and total before saving.',
                            false
                        );
                } else if (currentAction === 'rewrite_description') {
                    const targetIndex = aiTarget ? Number(aiTarget.value) : 0;
                    const target = lineItemRows()[targetIndex];
                    if (!target || !currentResult.text) {
                        throw new Error('Choose a line item and generate its rewrite again.');
                    }
                    target.querySelector('[data-field="description"]').value = currentResult.text;
                    setAiStatus('Description updated. Review it before saving.', false);
                } else if (currentAction === 'draft_message') {
                    const targetName = aiMode && aiMode.value === 'terms' ? 'terms' : 'notes';
                    const target = form.querySelector('[name="' + targetName + '"]');
                    if (target && currentResult.text) {
                        target.value = currentResult.text;
                        target.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                    setAiStatus(target ? 'Text added to the invoice. Review and edit it before saving.' : 'Message copied.', false);
                }

                if (currentAction !== 'explain_totals') {
                    aiPreview.hidden = true;
                }
            } catch (error) {
                setAiStatus(error instanceof Error ? error.message : 'Could not apply or copy the suggestion.', true);
            }
        });

        aiDismissButton.addEventListener('click', function () {
            clearAiPreview();
            setAiStatus('Suggestion dismissed.', false);
        });

        if (aiMode) {
            aiMode.addEventListener('change', updateTaskUi);
            updateTaskUi();
        }
        if (aiTarget) {
            aiTarget.addEventListener('change', function () {
                const row = lineItemRows()[Number(aiTarget.value) || 0];
                if (aiDescription && row) {
                    aiDescription.value = row.querySelector('[data-field="description"]').value;
                }
            });
        }
        if (invoiceTable) {
            invoiceTable.addEventListener('click', function (event) {
                if (event.target.closest('[data-add-row]')) {
                    window.setTimeout(function () { refreshRewriteTargets(true); }, 0);
                }
            });
        }
    });

    if (invoiceTable) {
        invoiceTable.addEventListener('input', function (event) {
            if (event.target.matches('[data-field="quantity"], [data-field="unit_price"], [data-field="discount"], [data-field="tax_rate"]')) {
                calculateRows();
            }
        });

        invoiceTable.addEventListener('click', function (event) {
            const removeButton = event.target.closest('.remove-row');
            if (removeButton && invoiceTable.querySelectorAll('tr.item-row').length > 1) {
                removeButton.closest('tr').remove();
                calculateRows();
            }
        });
    }

    if (addRowBtn && invoiceTable) {
        addRowBtn.addEventListener('click', function () {
            const rowTemplate = document.querySelector('#invoice-item-row-template');
            const tableBody = invoiceTable.querySelector('tbody');
            if (!rowTemplate || !tableBody) {
                return;
            }

            const row = rowTemplate.content.firstElementChild.cloneNode(true);
            tableBody.appendChild(row);
            calculateRows();
        });
    }

    if (invoiceTable) {
        calculateRows();
    }
});
