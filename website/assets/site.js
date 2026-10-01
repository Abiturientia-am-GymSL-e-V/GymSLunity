// Kopfzeile mit Trennlinie beim Scrollen, Einblenden von Abschnitten und
// Reiter für die weiteren Funktionsbereiche. Ohne JavaScript bleibt die Seite
// vollständig lesbar (Klasse no-js).

const header = document.querySelector('.header');
const onScroll = () =>
    header.classList.toggle('is-scrolled', window.scrollY > 8);
onScroll();
window.addEventListener('scroll', onScroll, { passive: true });

const observer = new IntersectionObserver(
    (entries) => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        }
    },
    { rootMargin: '0px 0px -10% 0px' },
);
document
    .querySelectorAll('.reveal, .hero-shot')
    .forEach((element) => observer.observe(element));

for (const tabs of document.querySelectorAll('[data-tabs]')) {
    const tablist = tabs.querySelector('[role="tablist"]');
    const buttons = [...tabs.querySelectorAll('[role="tab"]')];
    const select = (button, focus, initial = false) => {
        for (const other of buttons) {
            const selected = other === button;
            other.setAttribute('aria-selected', String(selected));
            other.tabIndex = selected ? 0 : -1;
            document.getElementById(
                other.getAttribute('aria-controls'),
            ).hidden = !selected;
        }
        if (initial) return;
        // Nur die Reiterleiste waagerecht nachführen, nie die Seite scrollen.
        const left =
            button.offsetLeft - (tablist.clientWidth - button.offsetWidth) / 2;
        tablist.scrollTo({ left, behavior: 'smooth' });
        if (focus) button.focus({ preventScroll: true });
    };
    buttons.forEach((button, index) => {
        button.addEventListener('click', () => select(button, false));
        button.addEventListener('keydown', (event) => {
            const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
            if (event.key === 'Home') select(buttons[0], true);
            else if (event.key === 'End') select(buttons.at(-1), true);
            else if (step)
                select(
                    buttons[(index + step + buttons.length) % buttons.length],
                    true,
                );
            else return;
            event.preventDefault();
        });
    });
    select(
        buttons.find(
            (button) => button.getAttribute('aria-selected') === 'true',
        ) ?? buttons[0],
        false,
        true,
    );
}
