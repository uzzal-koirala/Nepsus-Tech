// A styled "Are you sure?" box instead of the browser's confirm(), for wire:confirm and our own buttons.
// The markup lives in resources/views/partials/confirm-dialog.blade.php.

const DANGER = /^\s*(delete|remove|discard|clear|cancel|forget|archive)\b/i;

/** Shows the box and resolves true when the person confirms. Falls back to confirm() if the box is not on the page. */
function appConfirm(message, { danger = DANGER.test(message) } = {}) {
    const dialog = document.getElementById('app-confirm');
    if (!dialog) return Promise.resolve(window.confirm(message));

    const tone = danger ? 'danger' : 'question';
    const ok = dialog.querySelector('[data-confirm-ok]');
    dialog.querySelector('#app-confirm-text').textContent = message;
    dialog.querySelectorAll('[data-confirm-icon]').forEach((icon) => (icon.hidden = icon.dataset.confirmIcon !== tone));
    ok.dataset.tone = tone;
    ok.textContent = ok.dataset[danger ? 'labelDanger' : 'labelQuestion'];
    dialog.returnValue = '';

    return new Promise((resolve) => {
        // Clicking the dimmed backdrop cancels, like Escape.
        const onBackdrop = (e) => e.target === dialog && dialog.close('cancel');
        dialog.addEventListener('click', onBackdrop);
        dialog.addEventListener('close', () => {
            dialog.removeEventListener('click', onBackdrop);
            resolve(dialog.returnValue === 'ok');
        }, { once: true });
        dialog.showModal();
        ok.focus();
    });
}

window.appConfirm = appConfirm;

// Livewire runs wire:confirm through el.__livewire_confirm(action, instead); this replaces it after Livewire sets it.
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('directive.init', ({ el, directive }) => {
        if (directive.value !== 'confirm' || directive.modifiers.includes('prompt')) return;

        const message = directive.expression.replaceAll('\\n', '\n') || 'Are you sure?';
        el.__livewire_confirm = (action, instead) => {
            instead();
            appConfirm(message).then((yes) => yes && action());
        };
    });
});
