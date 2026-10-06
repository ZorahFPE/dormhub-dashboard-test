'use strict';
// Progressive enhancement only: the dashboard and household switch work without JS.
const copyButton = document.getElementById('copy-code');
if (copyButton) {
    copyButton.addEventListener('click', async () => {
        const codeNode = document.getElementById('join-code');
        const status = document.getElementById('copy-status');
        const code = codeNode.textContent.trim();
        try {
            if (!navigator.clipboard || !window.isSecureContext) {
                throw new Error('Clipboard not available');
            }
            await navigator.clipboard.writeText(code);
            status.textContent = 'Code copied.';
        } catch (_) {
            // HTTP/LAN demos may not have clipboard permission. Select the code.
            const range = document.createRange();
            range.selectNodeContents(codeNode);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            status.textContent = 'Code selected. Press Ctrl+C (or Command+C) to copy, or copy the text manually.';
        }
    });
}
