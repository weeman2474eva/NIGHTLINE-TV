const theme = document.querySelector("#theme");

if (theme) {
    function applyTheme() {
        document.documentElement.dataset.theme = theme.value;
    }

    const savedTheme = localStorage.getItem("nightline-theme");
    if (["blue", "mint", "amber"].includes(savedTheme)) {
        theme.value = savedTheme;
    }
    applyTheme();
    theme.addEventListener("change", () => {
        applyTheme();
        localStorage.setItem("nightline-theme", theme.value);
    });
}

if (document.body.classList.contains("tv")) {
    document.addEventListener("keydown", (event) => {
        const active = document.activeElement;
        if (active.matches("input, select, video") || !event.key.startsWith("Arrow")) return;
        const horizontal = event.key === "ArrowLeft" || event.key === "ArrowRight";
        const sign = event.key === "ArrowLeft" || event.key === "ArrowUp" ? -1 : 1;
        const items = Array.from(document.querySelectorAll("a, button, input, select"))
            .filter((item) => !item.disabled && item.getClientRects().length);
        if (!items.includes(active)) {
            items[0]?.focus();
            event.preventDefault();
            return;
        }
        const origin = active.getBoundingClientRect();
        const x = origin.left + origin.width / 2;
        const y = origin.top + origin.height / 2;
        let nearest;
        let best = Infinity;
        for (const item of items) {
            const box = item.getBoundingClientRect();
            const dx = box.left + box.width / 2 - x;
            const dy = box.top + box.height / 2 - y;
            const forward = sign * (horizontal ? dx : dy);
            const lateral = Math.abs(horizontal ? dy : dx);
            if (forward <= 2 || forward < lateral / 2) continue;
            const score = forward + lateral * 3;
            if (score < best) {
                best = score;
                nearest = item;
            }
        }
        if (nearest) {
            nearest.focus();
            event.preventDefault();
        }
    });
}
