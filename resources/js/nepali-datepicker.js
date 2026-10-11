// Bikram Sambat date picker. The bound value stays an AD "YYYY-MM-DD" string,
// so Livewire validation and storage are unchanged; only the UI is in BS.
import calendar from '../data/bs-calendar.json' with { type: 'json' };

const DAY = 86400000;
const EPOCH = Date.UTC(1943, 3, 14); // BS 2000/01/01
const YEARS = Object.keys(calendar).map(Number);
const MONTHS = ['Baisakh', 'Jestha', 'Asar', 'Shrawan', 'Bhadra', 'Ashoj', 'Kartik', 'Mangsir', 'Poush', 'Magh', 'Falgun', 'Chaitra'];
const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const isoToday = () => {
    const now = new Date();

    return new Date(Date.UTC(now.getFullYear(), now.getMonth(), now.getDate())).toISOString().slice(0, 10);
};

export function adToBs(iso) {
    const [y, m, d] = iso.split('-').map(Number);
    let days = Math.round((Date.UTC(y, m - 1, d) - EPOCH) / DAY);

    if (days < 0) return null;

    for (const year of YEARS) {
        for (let month = 0; month < 12; month++) {
            if (days < calendar[year][month]) return { y: year, m: month, d: days + 1 };
            days -= calendar[year][month];
        }
    }

    return null;
}

export function bsToAd(y, m, d) {
    let days = d - 1;

    for (const year of YEARS) {
        if (year === y) {
            for (let month = 0; month < m; month++) days += calendar[year][month];
            break;
        }
        days += calendar[year].reduce((a, b) => a + b, 0);
    }

    return new Date(EPOCH + days * DAY).toISOString().slice(0, 10);
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('nepaliDate', () => ({
        value: '',
        open: false,
        viewY: 0,
        viewM: 0,
        months: MONTHS,
        weekdays: WEEKDAYS,
        years: YEARS,

        get selected() {
            return this.value ? adToBs(this.value) : null;
        },

        get label() {
            const s = this.selected;

            return s ? `${s.d} ${MONTHS[s.m]} ${s.y}` : '';
        },

        get adLabel() {
            return this.value
                ? new Date(this.value + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
                : '';
        },

        get cells() {
            const offset = new Date(bsToAd(this.viewY, this.viewM, 1) + 'T00:00:00Z').getUTCDay();
            const length = calendar[this.viewY][this.viewM];

            return [...Array(offset).fill(null), ...Array.from({ length }, (_, i) => i + 1)];
        },

        isSelected(day) {
            const s = this.selected;

            return s && s.y === this.viewY && s.m === this.viewM && s.d === day;
        },

        isToday(day) {
            const t = adToBs(isoToday());

            return t && t.y === this.viewY && t.m === this.viewM && t.d === day;
        },

        toggle() {
            const base = this.selected ?? adToBs(isoToday());
            this.viewY = base.y;
            this.viewM = base.m;
            this.open = !this.open;
        },

        shift(step) {
            let month = this.viewM + step;
            let year = this.viewY + Math.floor(month / 12);
            month = ((month % 12) + 12) % 12;

            if (calendar[year]) {
                this.viewY = year;
                this.viewM = month;
            }
        },

        pick(day) {
            this.value = bsToAd(this.viewY, this.viewM, day);
            this.open = false;
        },

        pickToday() {
            this.value = isoToday();
            this.open = false;
        },

        clear() {
            this.value = '';
            this.open = false;
        },
    }));
});
