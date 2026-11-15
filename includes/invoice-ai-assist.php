<section class="ai-invoice-assist no-print" id="ai-assist-panel" data-ai-assist aria-labelledby="ai-assist-title" hidden>
    <div class="ai-assist-heading-row">
        <div class="ai-invoice-assist-copy">
            <span class="ai-assist-kicker">GEN AI</span>
            <h4 id="ai-assist-title">Gen AI Assistant</h4>
            <p>Ask a question or open invoice tools for help with this draft.</p>
        </div>
    </div>
    <section class="ai-chat-widget" data-ai-chat aria-labelledby="ai-chat-title">
        <div class="ai-chat-heading">
            <h5 id="ai-chat-title">Chat with Gen AI</h5>
        </div>
        <div class="ai-chat-messages" data-ai-chat-messages role="log" aria-live="polite" aria-relevant="additions text">
            <div class="ai-chat-message ai-chat-message-assistant">
                <strong>Gen AI</strong>
                <p>Ask a question, or paste a quote and choose Create invoice to extract its details.</p>
            </div>
        </div>
        <label class="sr-only" for="ai-chat-input">Your message</label>
        <div class="ai-chat-compose">
            <textarea id="ai-chat-input" rows="2" maxlength="4000" placeholder="Ask a question or paste quote details..." data-ai-chat-input></textarea>
            <div class="ai-chat-actions">
                <button class="btn btn-primary" type="button" data-ai-chat-send>Send</button>
                <button class="btn btn-secondary" type="button" data-ai-chat-create-invoice>Create invoice</button>
            </div>
        </div>
        <p class="ai-assist-status" data-ai-chat-status role="status" aria-live="polite"></p>
    </section>
    <details class="ai-assist-tools">
        <summary>Invoice tools</summary>
        <div class="ai-assist-tools-content">
            <label class="ai-assist-input-label" for="ai-assist-mode">Choose an invoice task</label>
            <select class="ai-assist-mode" id="ai-assist-mode" data-ai-mode aria-label="AI assistant task">
                <option value="draft_invoice">Extract an invoice from pasted text</option>
                <option value="draft_items">Add line items from a description</option>
                <option value="rewrite_description">Polish a line item description</option>
                <option value="note">Write an invoice note</option>
                <option value="terms">Draft payment terms</option>
                <option value="explain_totals">Explain these invoice totals</option>
            </select>
            <label class="ai-assist-input-label" for="ai-work-description" data-ai-prompt-label>Paste a quote, email, chat, or notes to extract invoice details</label>
            <label class="ai-assist-target" data-ai-target-wrap hidden>
                Apply the rewrite to
                <select data-ai-target aria-label="Choose line item to rewrite"></select>
            </label>
            <div class="ai-assist-compose" data-ai-compose>
                <textarea id="ai-work-description" maxlength="4000" rows="4" placeholder="Paste your quote or message here. Gen AI will extract the client, sender details, items, quantities, prices, dates, and terms when stated." data-ai-description></textarea>
                <button class="btn btn-primary" type="button" data-ai-generate>Extract invoice details</button>
            </div>
            <p class="ai-assist-status" data-ai-status role="status" aria-live="polite"></p>
            <div class="ai-assist-preview" data-ai-preview hidden>
                <div data-ai-items-container hidden>
                    <strong>Review suggestions</strong>
                    <ul data-ai-items></ul>
                </div>
                <dl class="ai-assist-details" data-ai-details hidden></dl>
                <div class="ai-assist-generated-text" data-ai-generated-text hidden>
                    <strong data-ai-subject hidden></strong>
                    <p data-ai-result-text></p>
                </div>
                <div class="ai-assist-preview-actions">
                    <button class="btn btn-primary" type="button" data-ai-apply>Use this draft</button>
                    <button class="btn btn-secondary" type="button" data-ai-dismiss>Dismiss</button>
                </div>
            </div>
        </div>
    </details>
</section>
<button class="ai-chat-toggle no-print" type="button" data-ai-chat-toggle aria-expanded="false" aria-controls="ai-assist-panel" aria-label="Open Gen AI chat" title="Chat with Gen AI">
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5 8 8 0 0 1-3.4-.76L4 20l1.22-4.1A7.47 7.47 0 0 1 4.5 12 7.5 7.5 0 1 1 20 11.5Z"></path>
    </svg>
</button>
