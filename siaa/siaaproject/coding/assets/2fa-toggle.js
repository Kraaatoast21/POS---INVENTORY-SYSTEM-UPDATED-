// Minimal script to keep 2FA toggle and status text in sync.
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('twofa-toggle');
    var status = document.getElementById('twofa-status');
    if (!toggle || !status) return;

    function updateStatus(checked) {
        status.textContent = checked ? 'ENABLED' : 'DISABLED';
        // Toggle visual classes (Tailwind classes used as example)
        status.classList.toggle('text-green-600', checked);
        status.classList.toggle('text-red-600', !checked);
    }

    // If server rendered an initial state on the status element via data-initial
    // (e.g. data-initial="1" or "enabled"), prefer that and sync the checkbox.
    var initial = status.dataset.initial;
    if (typeof initial !== 'undefined' && initial !== '') {
        var enabled = initial === '1' || /enable/i.test(initial) || /true/i.test(initial);
        toggle.checked = enabled;
        updateStatus(enabled);
    } else {
        // Fallback to the checkbox checked state
        updateStatus(toggle.checked);
    }

    // Update on user interaction and optionally send to server
    toggle.addEventListener('change', function () {
        updateStatus(toggle.checked);

        // Optional: synchronize to server (uncomment and adapt endpoint)
        /*
        fetch('/siaa/siaaproject/coding/update-2fa.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ enabled: toggle.checked ? 1 : 0 })
        }).then(function(res){
            if (!res.ok) console.error('2FA update failed');
        }).catch(function(err){
            console.error('2FA update error', err);
        });
        */
    });
});
