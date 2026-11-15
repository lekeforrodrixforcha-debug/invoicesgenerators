<?php if (!empty($_SESSION['user_id'])): ?>
        </main>
    </div>
<?php else: ?>
    </div>
<?php endif; ?>
<?php
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$assistantPage = [
    'dashboard.php' => 'dashboard',
    'invoices.php' => 'invoices',
    'invoice_create.php' => 'new_invoice',
    'templates.php' => 'new_invoice',
    'customers.php' => 'customers',
    'products.php' => 'products',
    'settings.php' => 'settings',
    'invoice.php' => 'invoices',
    'quotes.php' => 'invoices',
    'expenses.php' => 'expenses',
    'reports.php' => 'reports',
    'admin.php' => 'settings',
][$currentPage] ?? 'dashboard';
?>
<?php if (!empty($_SESSION['user_id']) && !$isAdminUser && $currentPage !== 'invoice_create.php'): ?>
    <button class="ai-chat-toggle no-print" type="button" data-global-ai-toggle aria-expanded="false" aria-controls="global-ai-panel" aria-label="Open Gen AI assistant" title="Chat with Gen AI">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5 8 8 0 0 1-3.4-.76L4 20l1.22-4.1A7.47 7.47 0 0 1 4.5 12 7.5 7.5 0 1 1 20 11.5Z"></path>
        </svg>
    </button>
    <section class="ai-global-assist no-print" id="global-ai-panel" data-global-ai-panel data-ai-page="<?= e($assistantPage); ?>" aria-labelledby="global-ai-title" hidden>
        <div class="ai-chat-heading">
            <div>
                <h5 id="global-ai-title">Gen AI Assistant</h5>
                <p>Ask for help or directions around your workspace.</p>
            </div>
            <button class="ai-global-close" type="button" data-global-ai-close aria-label="Close Gen AI assistant">&times;</button>
        </div>
        <div class="ai-chat-messages" data-global-ai-messages role="log" aria-live="polite" aria-relevant="additions text">
            <div class="ai-chat-message ai-chat-message-assistant">
                <strong>Gen AI</strong>
                <p>I can help you find workspace features, explain how to use them, and guide you to the right page.</p>
            </div>
        </div>
        <label class="sr-only" for="global-ai-input">Your message</label>
        <div class="ai-chat-compose">
            <textarea id="global-ai-input" rows="2" maxlength="2000" placeholder="How can I help?" data-global-ai-input></textarea>
            <button class="btn btn-primary" type="button" data-global-ai-send>Send</button>
        </div>
        <p class="ai-assist-status" data-global-ai-status role="status" aria-live="polite"></p>
    </section>
<?php endif; ?>
<script src="<?= e(publicAssetUrl('public/js/app.js')); ?>"></script>
</body>
</html>
